<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Componenta\Http\Router\Exception\RoutePatternException;
use Componenta\Http\Router\Internal\RouteRegex;
use Componenta\Http\Router\Internal\RoutePrefix;
use Componenta\Http\Router\Internal\RouteParameters;
use Componenta\Http\Router\Internal\SourceRouteIndex;
use Componenta\Http\Router\Syntax\ColonSyntax;
use Componenta\Http\Router\Syntax\AngleBracketSyntax;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;
use Generator;

/**
 * Optimized route collection for production.
 *
 * Performance characteristics:
 * - Static routes: O(1) hash lookup, zero regex
 * - Dynamic routes (unified regex): one combined PCRE match (cost depends on the expression)
 * - Dynamic routes (chunks): O(k) where k = relevant chunks
 * - Cached RouteRecord instances for public access
 * - Pre-compiled regex patterns in cache
 * - Retains source syntax for URL generation
 */
final class CompiledRoutes implements RouteCollectorInterface, MatcherInterface, GeneratorInterface
{
    private readonly SyntaxParserInterface $sourceSyntax;
    private ?Routes $sourceRoutes = null;
    public readonly CompilerInterface $compiler;

    /** @var array<string, RouteHandler> */
    private array $handlers = [];

    /** @var array<string, RouteRecord> */
    private array $records = [];

    /** @var array<string, MiddlewareGroup|null> */
    private array $middlewares = [];

    /** @param array<string, string> $defaultTokens */
    private function __construct(
        private readonly array $staticRoutes,
        private readonly array $regex,
        private readonly array $routeMap,
        private readonly array $dynamicChunks,
        private readonly array $prefixIndex,
        private readonly array $defaultTokens,
        private readonly array $routeData,
        ?SyntaxParserInterface $generationSyntax = null,
        ?SyntaxParserInterface $compilerSyntax = null,
        private readonly bool $explicitGenerationSyntax = true,
    ) {
        $this->sourceSyntax = $generationSyntax ?? new CompositeSyntax();
        $this->compiler = new Compiler($compilerSyntax, $defaultTokens);
    }

    /**
     * @param array{name: string, handler: mixed} $route
     */
    private function handlerFor(array $route): RouteHandler
    {
        $name = $route['name'];

        return $this->handlers[$name] ??= $route['handler'] instanceof RouteHandler
            ? $route['handler']
            : new RouteHandler($route['handler']);
    }

    /**
     * @param array{name: string, middlewares?: array} $route
     */
    private function middlewaresFor(array $route): ?MiddlewareGroup
    {
        $name = $route['name'];

        if (array_key_exists($name, $this->middlewares)) {
            return $this->middlewares[$name];
        }

        $middlewares = $route['middlewares'] ?? [];

        return $this->middlewares[$name] = $middlewares === []
            ? null
            : MiddlewareGroup::fromArray($middlewares);
    }

    /**
     * @param array{name: string, handler: mixed, middlewares?: array} $route
     * @param array<string, mixed> $parameters
     */
    private function matchResult(array $route, array $parameters): MatchResult
    {
        return new MatchResult(
            $route['name'],
            $this->handlerFor($route),
            $this->middlewaresFor($route),
            $parameters,
            $this,
        );
    }

    private function routeFor(string $name): array
    {
        return $this->routeDataFor($name);
    }

    /** @return array{name: string, path: string, handler: mixed, record?: array{path: string, ...}, defaults?: array<string, mixed>, tokens?: array<string, string>, optionalParams?: array<string, mixed>, ...} */
    private function routeDataFor(string $name): array
    {
        if (!isset($this->routeData[$name])) {
            throw new \LogicException(sprintf('Compiled route data for "%s" is missing.', $name));
        }

        return ['name' => $name] + $this->routeData[$name];
    }

    /** @return array{name: string, path: string, handler: mixed, tokens: array} */
    private function routeRecordDataFor(string $name): array
    {
        $data = $this->routeDataFor($name);
        return array_replace($data, $data['record']);
    }

    /**
     * Create instance from cache file.
     *
     * @param string $cacheFile Path to cache file generated by RouteCacheGenerator
     */
    public static function fromCache(string $cacheFile): self
    {
        return self::fromArray(self::readCache($cacheFile) ?? throw new \RuntimeException('Cannot read route cache: ' . $cacheFile));
    }

    public static function fromArray(array $data): self
    {
        return self::tryFromArray($data) ?? throw new \InvalidArgumentException('Invalid or incompatible route cache; rebuild from the original source.');
    }

    private static function hydrate(array $data): self
    {
        return new self(
            $data['staticRoutes'] ?? [],
            $data['regex'] ?? [],
            $data['routeMap'] ?? [],
            $data['dynamicChunks'] ?? [],
            $data['prefixIndex'] ?? [],
            $data['defaultTokens'] ?? CompilerInterface::DEFAULT_PATTERNS,
            $data['routeData'] ?? [],
            self::syntaxFromCache(array_key_exists('generationSyntax', $data) ? $data['generationSyntax'] : CompositeSyntax::class),
            self::syntaxFromCache(array_key_exists('compilerSyntax', $data) ? $data['compilerSyntax'] : CompositeSyntax::class),
            $data['explicitGenerationSyntax'] ?? false,
        );
    }

    private static function syntaxFromCache(mixed $syntax): SyntaxParserInterface
    {
        return match ($syntax) {
            CompositeSyntax::class => new CompositeSyntax(),
            CurlySyntax::class => new CurlySyntax(),
            SquareBracketSyntax::class => new SquareBracketSyntax(),
            AngleBracketSyntax::class => new AngleBracketSyntax(),
            ColonSyntax::class => new ColonSyntax(),
            default => throw new \InvalidArgumentException('Unknown route cache syntax.'),
        };
    }

    /** Invalid or missing generated data leaves runtime resolution to the source. */
    public static function tryFromCache(string $file): ?self
    {
        return self::tryFromArray(self::readCache($file));
    }

    /** Read a trusted standalone deployment artifact, never user input. */
    private static function readCache(string $file): ?array
    {
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || !is_readable($file)) {
            return null;
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if (DIRECTORY_SEPARATOR === '/') {
                $permissions = fileperms($file);
                if ($permissions === false || ($permissions & 0o022) !== 0) {
                    return null;
                }
            }
            $level = ob_get_level();
            if (!ob_start()) { return null; }
            try {
                $data = (static fn (string $file): mixed => require $file)($file);
                return ob_get_level() === $level + 1 && ob_get_contents() === '' && is_array($data) ? $data : null;
            } finally {
                while (ob_get_level() > $level) {
                    if (!ob_end_clean()) { break; }
                }
            }
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    /** Validate a prepared payload independently of its storage or application lifecycle. */
    public static function tryFromArray(mixed $data): ?self
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return self::fromCacheData($data);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    private static function fromCacheData(mixed $data): ?self
    {
        if (!is_array($data) || ($data['version'] ?? null) !== RouteCacheGenerator::CACHE_VERSION) {
            return null;
        }
        if (array_key_exists('explicitGenerationSyntax', $data) && !is_bool($data['explicitGenerationSyntax'])) { return null; }
        foreach (['staticRoutes', 'regex', 'routeMap', 'dynamicChunks', 'prefixIndex', 'defaultTokens', 'routeData'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) { return null; }
        }
        if (!self::isTokenMap($data['defaultTokens'] ?? [])) { return null; }
        $expected = [];
        $expectedDynamic = [];
        foreach ($data['routeData'] ?? [] as $name => $route) {
            if (!is_array($route) || !is_string($route['path'] ?? null)
                || !array_key_exists('handler', $route) || !is_array($route['record'] ?? null)
                || !is_string($route['record']['path'] ?? null)
                || !is_array($route['record']['tokens'] ?? null) || !is_array($route['record']['defaults'] ?? null)) {
                return null;
            }
            if (array_diff(array_keys($route['record']), ['path', 'tokens', 'defaults']) !== []
                || array_diff(array_keys($route), ['path', 'handler', 'record', 'methods', 'middlewares', 'tokens', 'defaults', 'paramNames', 'optionalParams', 'group']) !== []) {
                return null;
            }
            foreach (['methods', 'middlewares', 'tokens', 'defaults', 'paramNames', 'optionalParams'] as $key) {
                if (isset($route[$key]) && !is_array($route[$key])) { return null; }
            }
            if (!self::isTokenMap($route['tokens'] ?? []) || !self::isTokenMap($route['optionalParams'] ?? [], true)) {
                return null;
            }
            $paramNames = $route['paramNames'] ?? [];
            if (!array_is_list($paramNames)) { return null; }
            foreach ($paramNames as $paramName) {
                if (!is_string($paramName) || $paramName === '') { return null; }
            }
            if (count(array_unique($paramNames)) !== count($paramNames)) { return null; }
            foreach ($route['defaults'] ?? [] as $paramName => $value) {
                if (!is_string($paramName) || $paramName === '' || (!is_scalar($value) && $value !== null)) { return null; }
            }
            $record = RouteRecord::fromArray(array_replace(['name' => (string) $name] + $route, $route['record']));
            foreach ($record->methods as $method) {
                $expected[$method][(string) $name] = ['static' => $paramNames === [], 'path' => $record->path];
                if ($paramNames !== []) {
                    $expectedDynamic[$method][] = (string) $name;
                }
            }
        }
        // Every effective record must occur exactly once for each declared method.
        // No index may point at a route for a different method or static path.
        $seen = [];
        $seenDynamic = [];
        $visit = static function (mixed $method, mixed $name, bool $static, ?string $path = null) use (&$seen, &$seenDynamic, $expected): bool {
            if (!is_string($name) || !isset($expected[$method][$name]) || isset($seen[$method][$name])) {
                return false;
            }
            $record = $expected[$method][$name];
            if ($record['static'] !== $static || ($static && $record['path'] !== $path)) {
                return false;
            }
            $seen[$method][$name] = true;
            if (!$static) { $seenDynamic[$method][] = $name; }
            return true;
        };
        foreach ($data['staticRoutes'] ?? [] as $method => $entries) {
            if (!is_array($entries)) { return null; }
            foreach ($entries as $path => $name) {
                if (!is_string($path) || !$visit($method, $name, true, $path)) { return null; }
            }
        }
        if (($data['regex'] ?? []) !== [] && ($data['dynamicChunks'] ?? []) !== []) { return null; }
        if (array_keys($data['regex'] ?? []) !== array_keys($data['routeMap'] ?? [])) { return null; }
        foreach ($data['regex'] ?? [] as $method => $regex) {
            $map = $data['routeMap'][$method];
            if (!is_string($regex) || preg_match($regex, '') === false || !is_array($map) || $map === []) { return null; }
            if (array_keys($map) !== array_map(static fn (int $i): string => 'r' . $i, range(0, count($map) - 1))) { return null; }
            foreach ($map as $name) {
                if (!$visit($method, $name, false)) { return null; }
            }
        }
        $expectedPrefixes = [];
        $standardSyntax = ($data['compilerSyntax'] ?? CompositeSyntax::class) === CompositeSyntax::class;
        foreach ($data['dynamicChunks'] ?? [] as $method => $chunks) {
            if (!is_array($chunks) || !array_is_list($chunks)) { return null; }
            foreach ($chunks as $index => $chunk) {
                if (!is_array($chunk) || !is_string($chunk['regex'] ?? null) || preg_match($chunk['regex'], '') === false
                    || !is_array($chunk['routeMap'] ?? null) || $chunk['routeMap'] === []) { return null; }
                $map = $chunk['routeMap'];
                if (array_keys($map) !== array_map(static fn (int $i): string => 'r' . $i, range(0, count($map) - 1))) { return null; }
                foreach ($map as $name) {
                    if (!$visit($method, $name, false)) { return null; }
                    $prefix = RoutePrefix::forPattern($expected[$method][$name]['path'], $standardSyntax);
                    $expectedPrefixes[$method][$prefix][$index] = $index;
                }
            }
        }
        foreach ($expected as $method => $records) {
            if (count($records) !== count($seen[$method] ?? [])) { return null; }
        }
        foreach ($expectedDynamic as $method => $names) {
            if ($names !== ($seenDynamic[$method] ?? [])) { return null; }
        }
        foreach ($expectedPrefixes as $method => $prefixes) {
            $expectedPrefixes[$method] = array_map('array_values', $prefixes);
        }
        if (($data['prefixIndex'] ?? []) !== $expectedPrefixes) { return null; }

        return self::hydrate($data);
    }

    private static function isTokenMap(array $tokens, bool $nullable = false): bool
    {
        foreach ($tokens as $name => $pattern) {
            if (!is_string($name) || $name === '' || (!is_string($pattern) && !($nullable && $pattern === null))) {
                return false;
            }
            if ($pattern !== null && preg_match(RouteRegex::anchored($pattern), '') === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Match URI against routes.
     *
     * @param RouteCollectorInterface $routes Route collection (should be $this for optimal performance)
     * @param string $uri URI to match
     * @param string $method HTTP method
     * @return MatchResult Match result with route and parameters
     * @throws MethodNotAllowedException If URI matches but method not allowed
     * @throws RouteNotFoundException If no route matches
     */
    public function match(RouteCollectorInterface $routes, string $uri, string $method): MatchResult
    {
        if ($routes !== $this) {
            return $routes instanceof MatcherInterface
                ? $routes->match($routes, $uri, $method)
                : $this->matchGeneric($routes, $uri, $method);
        }

        $method = HttpMethod::normalize($method);

        $uri = str_starts_with($uri, '/') ? $uri : '/' . $uri;

        // Static route lookup - O(1)
        if (isset($this->staticRoutes[$method][$uri])) {
            $route = $this->routeFor($this->staticRoutes[$method][$uri]);
            return $this->matchResult($route, $route['defaults'] ?? []);
        }

        // Dynamic matching - unified regex or chunks
        $allowed = [];
        $result = $this->regex !== []
            ? $this->matchUnifiedRegex($uri, $method, $allowed)
            : $this->matchChunks($uri, $method, $allowed);

        if ($result !== null) {
            return $result;
        }

        // Collect allowed methods for static routes only on miss
        foreach ($this->staticRoutes as $m => $uris) {
            if (isset($uris[$uri])) {
                $allowed[] = (string) $m;
            }
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            sort($allowed, SORT_STRING);
            throw new MethodNotAllowedException($uri, $method, $allowed);
        }

        throw new RouteNotFoundException($uri, $method);
    }

    /**
     * Match against unified regex (single pattern per HTTP method).
     *
     * @param string $uri URI to match
     * @param string $method HTTP method
     * @param array $allowed Allowed methods collector (by reference)
     * @return MatchResult|null Match result or null if not matched
     */
    private function matchUnifiedRegex(string $uri, string $method, array &$allowed): ?MatchResult
    {
        if (isset($this->regex[$method])) {
            $map = $this->routeMap[$method];
            $matched = @preg_match($this->regex[$method], $uri, $m, PREG_UNMATCHED_AS_NULL);
            if ($matched === false) {
                if (count($map) > 1) {
                    return $this->matchSourcePatterns($uri, $method);
                }
                throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
            }
            if ($matched === 1) {
                $name = count($map) === 1 ? reset($map) : ($map[$m['MARK'] ?? ''] ?? null);
                if (!is_string($name)) { return $this->matchSourcePatterns($uri, $method); }
                $d = $this->routeFor($name);
                return $this->matchResult($d, $this->extractParams($m, $d));
            }
        }

        foreach ($this->regex as $m => $regex) {
            if ((string) $m === $method) { continue; }
            $matched = @preg_match($regex, $uri);
            if ($matched === false) {
                if (count($this->routeMap[$m]) > 1) {
                    return $this->matchSourcePatterns($uri, $method);
                }
                throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
            }
            if ($matched === 1) {
                $allowed[] = (string) $m;
            }
        }

        return null;
    }

    /**
     * Match against chunked regex patterns.
     *
     * Uses prefix index to reduce chunks to search.
     *
     * @param string $uri URI to match
     * @param string $method HTTP method
     * @param array $allowed Allowed methods collector (by reference)
     * @return MatchResult|null Match result or null if not matched
     */
    private function matchChunks(string $uri, string $method, array &$allowed): ?MatchResult
    {
        if (isset($this->dynamicChunks[$method])) {
            $prefix = ($p = strpos($uri, '/', 1)) !== false ? substr($uri, 1, $p - 1) : substr($uri, 1);

            $chunksToSearch = array_values(array_unique([
                ...($this->prefixIndex[$method][$prefix] ?? []),
                ...($this->prefixIndex[$method]['*'] ?? []),
            ]));

            if ($chunksToSearch === []) {
                $chunksToSearch = array_keys($this->dynamicChunks[$method]);
            } else {
                sort($chunksToSearch);
            }

            foreach ($chunksToSearch as $chunkIndex) {
                if (!isset($this->dynamicChunks[$method][$chunkIndex])) {
                    continue;
                }

                $chunk = $this->dynamicChunks[$method][$chunkIndex];

                $map = $chunk['routeMap'];
                $matched = @preg_match($chunk['regex'], $uri, $m, PREG_UNMATCHED_AS_NULL);
                if ($matched === false) {
                    if (count($map) > 1) {
                        return $this->matchSourcePatterns($uri, $method);
                    }
                    throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
                }
                if ($matched === 1) {
                    $name = count($map) === 1 ? reset($map) : ($map[$m['MARK'] ?? ''] ?? null);
                if (!is_string($name)) { return $this->matchSourcePatterns($uri, $method); }
                $d = $this->routeFor($name);
                    return $this->matchResult($d, $this->extractParams($m, $d));
                }
            }
        }

        foreach ($this->dynamicChunks as $m => $methodChunks) {
            if ((string) $m === $method) {
                continue;
            }
            foreach ($methodChunks as $chunk) {
                $matched = @preg_match($chunk['regex'], $uri);
                if ($matched === false) {
                    if (count($chunk['routeMap']) > 1) {
                        return $this->matchSourcePatterns($uri, $method);
                    }
                    throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
                }
                if ($matched === 1) {
                    $allowed[] = (string) $m;
                    break;
                }
            }
        }

        return null;
    }

    private function matchSourcePatterns(string $uri, string $method): MatchResult
    {
        // Combined expressions can exhaust PCRE limits before individual routes do.
        $source = $this->sourceRoutes ??= Routes::fromResolved($this, $this->compiler, $this->explicitGenerationSyntax ? $this->sourceSyntax : null);

        return $source->match($source, $uri, $method);
    }

    /**
     * Extract matched parameters with type coercion.
     *
     * Numeric strings are converted to integers or floats.
     *
     * @param array $matches Regex matches
     * @param array $routeData Route data with paramNames and defaults
     * @return array<string, mixed> Extracted parameters
     */
    private function extractParams(array $matches, array $routeData): array
    {
        return RouteParameters::extract($matches, $routeData['paramNames'] ?? [], $routeData['defaults'] ?? []);
    }

    /**
     * Match against non-compiled route collection.
     *
     * Fallback for when routes !== $this. Compiles routes on-the-fly.
     *
     * @param RouteCollectorInterface $routes Route collection
     * @param string $uri URI to match
     * @param string $method HTTP method
     * @return MatchResult Match result
     * @throws MethodNotAllowedException If URI matches but method not allowed
     * @throws RouteNotFoundException If no route matches
     */
    private function matchGeneric(RouteCollectorInterface $routes, string $uri, string $method): MatchResult
    {
        return (new SourceRouteIndex($routes, $this->compiler))->match($uri, $method);
    }

    /**
     * Generate URL for named route.
     *
     * @param RouteCollectorInterface $routes Route collection (should be $this for optimal performance)
     * @param string $name Route name
     * @param array<string, mixed> $parameters Route parameters
     * @return string Generated URL
     * @throws RouteNotRegisteredException If route not found
     */
    public function generate(RouteCollectorInterface $routes, string $name, array $parameters = []): string
    {
        if ($routes !== $this) {
            return $routes instanceof GeneratorInterface
                ? $routes->generate($routes, $name, $parameters)
                : $this->generateFromRoute($routes->getRoute($name), $parameters);
        }

        if (!isset($this->routeData[$name])) {
            throw new RouteNotRegisteredException($name);
        }

        $d = $this->routeDataFor($name);

        $path = $d['record']['path'];

        return $this->sourceSyntax->buildPath(
            $path,
            $parameters + ($d['defaults'] ?? []),
            [
                ...$this->defaultTokens,
                ...($d['tokens'] ?? []),
            ],
            $d['optionalParams'] ?? [],
            $name
        );
    }

    /**
     * Generate URL from non-cached route.
     *
     * Compiles route on-the-fly and preserves its generation syntax.
     *
     * @param RouteRecord $route Route record
     * @param array<string, mixed> $parameters Route parameters
     * @return string Generated URL
     */
    private function generateFromRoute(RouteRecord $route, array $parameters): string
    {
        $compiled = $this->compiler->compile(
            $route->path,
            $route->tokens,
            $route->defaults
        );

        $syntax = $this->explicitGenerationSyntax ? $this->sourceSyntax
            : ($this->compiler instanceof Compiler ? $this->compiler->syntax : new CompositeSyntax());

        return $syntax->buildPath(
            $route->path,
            $parameters + $compiled->defaults,
            $compiled->tokens,
            $compiled->optionalParameters,
            $route->name
        );
    }

    /**
     * Check if route exists by name.
     */
    public function has(string $name): bool
    {
        return isset($this->routeData[$name]);
    }

    /**
     * Get route by name.
     *
     * @throws RouteNotRegisteredException If route not found
     */
    public function getRoute(string $name): RouteRecord
    {
        if (!isset($this->routeData[$name])) {
            throw new RouteNotRegisteredException($name);
        }

        if (!isset($this->records[$name])) {
            $data = $this->routeRecordDataFor($name);
            $data['handler'] = $this->handlerFor($data);
            $data['middlewares'] = $this->middlewaresFor($data) ?? [];
            $this->records[$name] = RouteRecord::fromArray($data);
        }

        return $this->records[$name];
    }

    /**
     * Iterate over all routes.
     *
     * @return Generator<string, RouteRecord>
     */
    public function getIterator(): Generator
    {
        foreach ($this->routeData as $name => $data) {
            yield $name => $this->getRoute((string) $name);
        }
    }

    /**
     * Get total route count.
     */
    public function count(): int
    {
        return count($this->routeData);
    }

    /**
     * Convert to array of route records.
     *
     * @return array<string, RouteRecord>
     */
    public function toArray(): array
    {
        $routes = [];
        foreach ($this->routeData as $name => $_) {
            $routes[$name] = $this->getRoute((string) $name);
        }

        return $routes;
    }
}
