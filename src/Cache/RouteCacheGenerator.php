<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Cache;

use RuntimeException;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\HttpMethod;
use Componenta\Http\Router\Syntax\SyntaxConverter;
use Componenta\VarExport\Export;

/**
 * Generates optimized route cache.
 *
 * Converts all routes to ColonSyntax for fastest production performance.
 * Uses unified regex for <= 5000 dynamic routes (if regex fits in PCRE limit), chunks otherwise.
 */
final class RouteCacheGenerator
{
    private const int REGEX_LIMIT = 5000;
    private const int CHUNK_SIZE = 50;
    private const int MAX_REGEX_SIZE = 900_000;

    public function __construct(
        public CompilerInterface $compiler = new Compiler(),
        public SyntaxConverter $converter = new SyntaxConverter(),
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
        $this->writeFile($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . Export::pretty($data) . ";\n");
    }

    /**
     * Compile routes into cache-ready data structure.
     *
     * Separates static and dynamic routes, chooses optimal storage strategy.
     *
     * @param RouteCollectorInterface $routes Routes to compile
     * @return array{staticRoutes: array, routeData: array, regex?: array, routeMap?: array, dynamicChunks?: array, prefixIndex?: array}
     */
    public function compile(RouteCollectorInterface $routes): array
    {
        $staticRoutes = [];
        $dynamicRoutes = [];
        $routeData = [];

        foreach ($routes as $route) {
            $compiled = $this->compiler->compile(
                $route->path,
                $route->tokens,
                $route->defaults
            );

            $data = [
                'name' => $route->name,
                'path' => $this->converter->toColonSyntax($route->path),
                'handler' => $route->handler->value,
                'methods' => $route->methods,
                'middlewares' => $route->middlewares?->toArray() ?? [],
                'tokens' => $compiled->tokens,
                'defaults' => $compiled->defaults,
                'paramNames' => $compiled->parameterNames(),
                'optionalParams' => $compiled->optionalParameters,
                'group' => $route->group,
            ];

            $routeData[$route->name] = $data;

            foreach ($route->methods as $method) {
                $method = HttpMethod::normalize($method);

                if ($compiled->hasParameters()) {
                    $dynamicRoutes[$method][] = [
                        'data' => $data,
                        'compiled' => $compiled,
                        'prefix' => $this->getFirstSegment($route->path),
                    ];
                } else {
                    $staticRoutes[$method][$route->path] = $data;
                }
            }
        }

        $totalDynamic = array_sum(array_map('count', $dynamicRoutes));

        if ($totalDynamic <= self::REGEX_LIMIT) {
            $unifiedCache = $this->tryBuildUnifiedRegexCache($staticRoutes, $dynamicRoutes, $routeData);
            if ($unifiedCache !== null) {
                return $unifiedCache;
            }
        }

        return $this->buildChunkedCache($staticRoutes, $dynamicRoutes, $routeData);
    }

    private function getFirstSegment(string $path): string
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $s): bool => $s !== ''
        ));

        if ($segments === []) {
            return '';
        }

        $first = $segments[0];

        // If contains parameter markers, return wildcard
        if (preg_match('/[\[\]{}<>:]/', $first)) {
            return '*';
        }

        return $first;
    }

    /**
     * Attempt to build cache with single unified regex per HTTP method.
     *
     * Returns null if any regex exceeds PCRE size limit or fails to compile.
     * This is the fastest matching strategy for small-to-medium route sets.
     *
     * @param array<string, array<string, array>> $staticRoutes Static routes by method
     * @param array<string, list<array>> $dynamicRoutes Dynamic routes by method
     * @param array<string, array> $routeData All route data indexed by name
     * @return array{staticRoutes: array, routeData: array, regex: array, routeMap: array}|null
     */
    private function tryBuildUnifiedRegexCache(array $staticRoutes, array $dynamicRoutes, array $routeData): ?array
    {
        $regexMap = [];
        $routeMap = [];

        foreach ($dynamicRoutes as $method => $methodRoutes) {
            [$pattern, $routeMap[$method]] = $this->buildRegexPattern($methodRoutes);

            if (strlen($pattern) > self::MAX_REGEX_SIZE || @preg_match($pattern, '') === false) {
                return null;
            }

            $regexMap[$method] = $pattern;
        }

        return compact('staticRoutes', 'routeData') + ['regex' => $regexMap, 'routeMap' => $routeMap];
    }

    /**
     * Build cache with chunked regex groups.
     *
     * Fallback strategy for large route sets. Splits routes into chunks
     * and builds prefix index for faster chunk lookup during matching.
     *
     * @param array<string, array<string, array>> $staticRoutes Static routes by method
     * @param array<string, list<array>> $dynamicRoutes Dynamic routes by method
     * @param array<string, array> $routeData All route data indexed by name
     * @return array{staticRoutes: array, dynamicChunks: array, prefixIndex: array, routeData: array}
     */
    private function buildChunkedCache(array $staticRoutes, array $dynamicRoutes, array $routeData): array
    {
        $dynamicChunks = [];
        $prefixIndex = [];

        foreach ($dynamicRoutes as $method => $methodRoutes) {
            $chunkSize = max(30, min(self::CHUNK_SIZE, (int)(count($methodRoutes) / 5)));
            $dynamicChunks[$method] = [];

            foreach (array_chunk($methodRoutes, $chunkSize) as $chunkIndex => $chunkRoutes) {
                [$regex, $chunkRouteMap] = $this->buildRegexPattern($chunkRoutes);
                $dynamicChunks[$method][$chunkIndex] = compact('regex') + ['routeMap' => $chunkRouteMap];

                foreach ($chunkRoutes as $routeInfo) {
                    $prefixIndex[$method][$routeInfo['prefix']][] = $chunkIndex;
                }
            }

            if (isset($prefixIndex[$method])) {
                $prefixIndex[$method] = array_map(
                    fn($indices) => array_values(array_unique($indices)),
                    $prefixIndex[$method]
                );
            }
        }

        return compact('staticRoutes', 'dynamicChunks', 'prefixIndex', 'routeData');
    }

    /**
     * Build combined regex pattern with MARK verbs for route identification.
     *
     * @param list<array{data: array, compiled: object, prefix: string}> $routes Route info array
     * @return array{string, array<string, array>} Tuple of [regex pattern, route map]
     */
    private function buildRegexPattern(array $routes): array
    {
        $patterns = [];
        $routeMap = [];

        foreach ($routes as $index => $routeInfo) {
            $mark = 'r' . $index;
            $patterns[] = '(' . $routeInfo['compiled']->regex . ')(*MARK:' . $mark . ')';
            $routeMap[$mark] = $routeInfo['data'];
        }

        return ['#^(?:' . implode('|', $patterns) . ')$#J', $routeMap];
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

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Failed to create directory: $directory");
        }

        $tempFile = $filename . '.tmp.' . uniqid('', true);

        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write file: $tempFile");
        }

        if (!rename($tempFile, $filename)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to rename file to: $filename");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filename, true);
        }
    }
}
