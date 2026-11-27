<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Cache;

use RuntimeException;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Exception\UnsupportedRouteCacheException;
use Componenta\Http\Router\HttpMethod;
use Componenta\Http\Router\Internal\RouteRegex;
use Componenta\Http\Router\Internal\PreparedMap;
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
 * Retains original route syntax and individual expressions. The source and
 * prepared collections execute the same ordered index.
 */
final class RouteCacheGenerator
{
    public const int CACHE_VERSION = 17;

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
     * @return array{version: int, fingerprint: ?string, staticRoutes?: array, routeData?: array, dynamicIndex?: array, methodAliases?: array, defaultTokens?: array, generationSyntax?: class-string, compilerSyntax?: class-string, explicitGenerationSyntax?: bool}
     */
    public function compile(RouteCollectorInterface $routes): array
    {
        if (!$this->compiler instanceof Compiler) {
            throw new UnsupportedRouteCacheException('Route compiler cannot be preserved in a cache; use the original route source.');
        }

        if (($routes instanceof Routes || $routes instanceof CompiledRoutes) && $routes->compiler != $this->compiler) {
            throw new UnsupportedRouteCacheException('Route cache must use the source collection compiler.');
        }

        $explicitSyntax = ($routes instanceof Routes || $routes instanceof CompiledRoutes) ? $routes->syntax : null;
        $syntax = $explicitSyntax ?? $this->compiler->syntax;
        foreach (['generation' => $syntax, 'compiler' => $this->compiler->syntax] as $role => $parser) {
            if (!in_array($parser::class, [
                CompositeSyntax::class, CurlySyntax::class, SquareBracketSyntax::class,
                AngleBracketSyntax::class, ColonSyntax::class,
            ], true) || ($parser instanceof CompositeSyntax && $parser != new CompositeSyntax())) {
                throw new UnsupportedRouteCacheException(sprintf('Route %s syntax cannot be preserved in a cache; use the original route source.', $role));
            }
        }

        $routeData = [];
        $defaultTokens = $this->compiler->compile('/')->tokens;

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
            $optional = [
                'methods' => $route->methods === [HttpMethod::GET] ? null : $route->methods,
                'middlewares' => $route->middlewares?->toArray() ?: null,
                'tokens' => array_diff_assoc($compiled->tokens, $defaultTokens) ?: null,
                'defaults' => $compiled->defaults ?: null,
                'paramNames' => $parameterNames ?: null,
                'regex' => $compiled->hasParameters() ? RouteRegex::anchored($compiled->regex) : null,
                'optionalParams' => $compiled->optionalParameters ?: null,
                'group' => $route->group,
            ];

            foreach ($optional as $key => $value) {
                if ($value !== null) {
                    $data[$key] = $value;
                }
            }

            $routeData[$route->name] = $data;

        }

        return $this->compactCache(
            $index->export() + ['routeData' => $routeData],
            $defaultTokens,
            $syntax::class,
            $this->compiler->syntax::class,
            $explicitSyntax !== null,
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
