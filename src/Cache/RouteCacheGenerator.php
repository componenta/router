<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Cache;

use RuntimeException;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\HttpMethod;
use Componenta\Http\Router\Internal\RouteRegex;
use Componenta\Http\Router\Internal\RoutePrefix;
use Componenta\Http\Router\Internal\PreparedMap;
use Componenta\Http\Router\Internal\RouteMethodGroups;
use Componenta\Http\Router\Internal\SourceRouteIndex;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\AngleBracketSyntax;
use Componenta\Http\Router\Syntax\ColonSyntax;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;
use Componenta\VarExport\VarExport;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\EnumExporter;
use Componenta\VarExport\ScalarExporter;

/**
 * Generates optimized route cache.
 *
 * Retains the original route syntax and combines compatible adjacent routes
 * into bounded expressions, preserving registration order.
 * Expressions with capture references or custom regex constructs retain their original scope.
 */
final class RouteCacheGenerator
{
    public const int CACHE_VERSION = 14;

    private const int CHUNK_SIZE = 24;
    private const int MAX_REGEX_SIZE = 900_000;

    public function __construct(
        public readonly CompilerInterface $compiler = new Compiler(),
    ) {}

    /**
     * Generate cache file from route collection.
     *
     * @param RouteCollectorInterface $routes Routes to cache
     * @param string $cacheFile Target file path
     * @throws RuntimeException If file cannot be written
     */
    public function generate(RouteCollectorInterface $routes, string $cacheFile): void
    {
        $data = $this->compile($routes);
        $this->writeFile($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . (new VarExport(new ScalarExporter(), new ArrayExporter(), new EnumExporter()))->export($data) . ";\n");
    }

    /**
     * Compile routes into cache-ready data structure.
     *
     * Separates static and dynamic routes, chooses optimal storage strategy.
     *
     * @param RouteCollectorInterface $routes Routes to compile
     * @return array{version: int, fingerprint: ?string, staticRoutes?: array, routeData?: array, dynamicChunks?: array, prefixIndex?: array, methodAliases?: array, defaultTokens?: array, generationSyntax?: class-string, compilerSyntax?: class-string, explicitGenerationSyntax?: bool}
     */
    public function compile(RouteCollectorInterface $routes): array
    {
        if (!$this->compiler instanceof Compiler) {
            throw new RuntimeException('Route compiler cannot be preserved in a cache; use the original route source.');
        }

        if ($routes instanceof Routes && $routes->compiler != $this->compiler) {
            throw new RuntimeException('Route cache must use the source collection compiler.');
        }

        $syntax = $routes instanceof Routes && $routes->syntax !== null
            ? $routes->syntax
            : $this->compiler->syntax;
        foreach (['generation' => $syntax, 'compiler' => $this->compiler->syntax] as $role => $parser) {
            if (!in_array($parser::class, [
                CompositeSyntax::class, CurlySyntax::class, SquareBracketSyntax::class,
                AngleBracketSyntax::class, ColonSyntax::class,
            ], true) || ($parser instanceof CompositeSyntax && $parser != new CompositeSyntax())) {
                throw new RuntimeException(sprintf('Route %s syntax cannot be preserved in a cache; use the original route source.', $role));
            }
        }

        $staticRoutes = [];
        $dynamicRoutes = [];
        $routeData = [];
        $defaultTokens = $this->compiler->compile('/')->tokens;
        $standardSyntax = $this->compiler instanceof Compiler
            && $this->compiler->syntax == new CompositeSyntax();

        // Snapshot effective records once. Source and prepared paths share
        // the same compiler validation and conflict semantics.
        $records = iterator_to_array($routes, false);
        $index = new SourceRouteIndex($records, $this->compiler);
        foreach ($records as $route) {
            $compiled = $index->pattern($route->name);

            $data = [
                'path' => $route->path,
                'handler' => $route->handler->value,
            ];
            if ($route->tokens !== [] || $route->defaults !== []) {
                $data['record'] = array_filter(
                    ['tokens' => $route->tokens, 'defaults' => $route->defaults],
                    static fn (array $values): bool => $values !== [],
                );
            }

            $parameterNames = $compiled->parameterNames();
            $combinable = $standardSyntax && !in_array('MARK', $parameterNames, true)
                && $this->canCombineTokens(array_intersect_key($compiled->tokens, array_fill_keys($parameterNames, true)));

            $optional = [
                'methods' => $route->methods === [HttpMethod::GET] ? null : $route->methods,
                'middlewares' => $route->middlewares?->toArray() ?: null,
                'tokens' => array_diff_assoc($compiled->tokens, $defaultTokens) ?: null,
                'defaults' => $compiled->defaults ?: null,
                'paramNames' => $parameterNames ?: null,
                'optionalParams' => $compiled->optionalParameters ?: null,
                'group' => $route->group,
            ];

            foreach ($optional as $key => $value) {
                if ($value !== null) {
                    $data[$key] = $value;
                }
            }

            $routeData[$route->name] = $data;

            $prefix = $compiled->hasParameters()
                ? RoutePrefix::indexedKey($route->path, $standardSyntax, $compiled->tokens, $parameterNames)
                : null;
            foreach ($route->methods as $method) {
                $method = HttpMethod::normalize($method);

                if ($prefix !== null) {
                    $dynamicRoutes[$method][] = [
                        'route' => $route->name,
                        'compiled' => $compiled,
                        'prefix' => $prefix,
                        'combinable' => $combinable,
                    ];
                } else {
                    $staticRoutes[$method][$route->path] = $route->name;
                }
            }
        }

        return $this->compactCache(
            $this->buildChunkedCache($staticRoutes, $dynamicRoutes, $routeData),
            $defaultTokens,
            $syntax::class,
            $this->compiler->syntax::class,
            $routes instanceof Routes && $routes->syntax !== null,
        );
    }

    /**
     * @param array<string, array> $cache
     * @param array<string, string> $defaultTokens
     * @param class-string $generationSyntax
     * @param class-string $compilerSyntax
     * @return array<string, array|int|string|bool>
     */
    private function compactCache(array $cache, array $defaultTokens, string $generationSyntax, string $compilerSyntax, bool $explicitGenerationSyntax): array
    {
        $cache = array_filter(
            $cache,
            static fn (array $section): bool => $section !== [],
        );

        $cache = ['version' => self::CACHE_VERSION, ...$cache];
        if ($explicitGenerationSyntax) {
            $cache['explicitGenerationSyntax'] = true;
        }

        if ($compilerSyntax !== CompositeSyntax::class) {
            $cache['compilerSyntax'] = $compilerSyntax;
        }

        if ($generationSyntax !== CompositeSyntax::class) {
            $cache['generationSyntax'] = $generationSyntax;
        }

        if ($defaultTokens !== CompilerInterface::DEFAULT_PATTERNS) {
            $cache['defaultTokens'] = $defaultTokens;
        }

        return PreparedMap::seal($cache);
    }

    /** @param array<array-key, mixed> $tokens */
    private function canCombineTokens(array $tokens): bool
    {
        foreach ($tokens as $pattern) {
            // Only known built-in constraints qualify. Custom expressions can
            // have different resource-limit behavior when combined.
            if ($pattern !== '[^/]+' && !in_array($pattern, CompilerInterface::DEFAULT_PATTERNS, true)) {
                return false;
            }
        }

        return true;
    }

    private function canCompileRegex(string $pattern): bool
    {
        if (strlen($pattern) > self::MAX_REGEX_SIZE) {
            return false;
        }

        // A failed optimization probe must not reach the application's error handler.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Build cache with chunked regex groups.
     *
     * Fallback strategy for large route sets. Splits routes into chunks
     * and builds prefix index for faster chunk lookup during matching.
     *
     * @param array<string, array<string, string>> $staticRoutes Static routes by method
     * @param array<string, list<array>> $dynamicRoutes Dynamic routes by method
     * @param array<string, array> $routeData All route data indexed by name
     * @return array{staticRoutes: array, dynamicChunks: array, prefixIndex: array, routeData: array, methodAliases: array}
     */
    private function buildChunkedCache(array $staticRoutes, array $dynamicRoutes, array $routeData): array
    {
        $dynamicChunks = [];
        $prefixIndex = [];
        $methodAliases = RouteMethodGroups::aliases(array_map(static fn (array $routes): array => array_column($routes, 'route'), $dynamicRoutes));

        foreach ($dynamicRoutes as $method => $methodRoutes) {
            if (isset($methodAliases[$method])) { continue; }
            $dynamicChunks[$method] = [];

            for ($offset = 0, $count = count($methodRoutes); $offset < $count;) {
                $chunkSize = 1;
                if ($methodRoutes[$offset]['combinable']) {
                    while ($chunkSize < self::CHUNK_SIZE && $offset + $chunkSize < $count
                        && $methodRoutes[$offset + $chunkSize]['combinable']) { ++$chunkSize; }
                }
                $chunkRoutes = array_slice($methodRoutes, $offset, $chunkSize);
                [$regex, $chunkRouteMap] = $this->buildRegexPattern($chunkRoutes);
                while (count($chunkRoutes) > 1 && !$this->canCompileRegex($regex)) {
                    $chunkRoutes = array_slice($chunkRoutes, 0, intdiv(count($chunkRoutes), 2));
                    [$regex, $chunkRouteMap] = $this->buildRegexPattern($chunkRoutes);
                }

                $chunkIndex = count($dynamicChunks[$method]);
                $chunk = ['routeMap' => $chunkRouteMap];
                if (count($chunkRoutes) > 1) {
                    // Store each expression once; reconstruct the combined
                    // expression when loading the prepared map.
                    $chunk['individual'] = array_map(static fn (array $entry): string => $entry['compiled']->regex, $chunkRoutes);
                } else {
                    $chunk['regex'] = $regex;
                }
                $dynamicChunks[$method][$chunkIndex] = $chunk;

                foreach ($chunkRoutes as $routeInfo) {
                    $prefixIndex[$method][$routeInfo['prefix']][] = $chunkIndex;
                }
                $offset += count($chunkRoutes);
            }

            if (isset($prefixIndex[$method])) {
                $prefixIndex[$method] = array_map(
                    fn($indices) => array_values(array_unique($indices)),
                    $prefixIndex[$method]
                );
            }
        }

        return compact('staticRoutes', 'dynamicChunks', 'prefixIndex', 'routeData', 'methodAliases');
    }

    /**
     * Build combined regex pattern with MARK verbs for route identification.
     *
     * @param list<array{route: string, compiled: \Componenta\Http\Router\RouteCompileResult, prefix: string}> $routes Route info array
     * @return array{string, array<string, string>} Tuple of [regex pattern, route map]
     */
    private function buildRegexPattern(array $routes): array
    {
        if (count($routes) === 1) {
            return [RouteRegex::anchored($routes[0]['compiled']->regex), ['r0' => $routes[0]['route']]];
        }

        $patterns = [];
        $routeMap = [];

        foreach ($routes as $index => $routeInfo) {
            $mark = 'r' . $index;
            $patterns[] = $routeInfo['compiled']->regex;
            $routeMap[$mark] = $routeInfo['route'];
        }

        return [RouteRegex::combined($patterns), $routeMap];
    }

    /**
     * Atomically write content to file.
     *
     * Uses temp file + rename for atomic write. Invalidates OPcache if available.
     *
     * @param string $filename Target file path
     * @param string $content File content
     * @throws RuntimeException If directory creation or file write fails
     */
    private function writeFile(string $filename, string $content): void
    {
        $directory = dirname($filename);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: $directory");
        }

        $tempFile = $filename . '.tmp.' . bin2hex(random_bytes(16));
        $stream = fopen($tempFile, 'xb');
        if ($stream === false) {
            throw new RuntimeException("Failed to create temporary file: $tempFile");
        }

        try {
            if (DIRECTORY_SEPARATOR === '/') {
                $stat = fstat($stream);
                if ($stat === false || !chmod($tempFile, ($stat['mode'] & 0o777) & ~0o022)) {
                    throw new RuntimeException("Failed to secure temporary file: $tempFile");
                }
            }
            if (fwrite($stream, $content) !== strlen($content) || !fflush($stream)) {
                throw new RuntimeException("Failed to write complete file: $tempFile");
            }
            if (!fclose($stream)) {
                throw new RuntimeException("Failed to close temporary file: $tempFile");
            }
            if (!rename($tempFile, $filename)) {
                throw new RuntimeException("Failed to publish route cache: $filename");
            }
            $tempFile = null;
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if ($tempFile !== null && is_file($tempFile)) { @unlink($tempFile); }
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filename, true);
        }
    }
}
