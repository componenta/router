<?php
declare(strict_types=1);
namespace Componenta\Http\Router\Factory;

use Componenta\Config\ContainerValue;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Locator\CachedRouteLocator;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Stdlib\PathResolverInterface;
use InvalidArgumentException;

final readonly class RouteLocatorFactory
{
    public function __invoke(ContainerValue $container): RouteLocatorInterface
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);
        $routesFile = $paths->resolve($container->config->string(ConfigKey::ROUTES_FILE));
        $source = new RouteLocator($routesFile, $container->get(CompilerInterface::class, CompilerInterface::class), useCache: false);
        if ($container->config->environment->match('APP_ENV', 'production')
            && $container->config->bool(ConfigKey::COMPILED_PIPELINE, true)) {
            return new CachedRouteLocator(self::cacheFile($container), static fn (): RouteLocator => $source);
        }
        return $source;
    }

    /** @param list<string> $reservedPaths Other artifact paths owned by the integrating application. */
    public static function cacheFile(ContainerValue $container, array $reservedPaths = []): string
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);
        $routes = $paths->resolve($container->config->string(ConfigKey::ROUTES_FILE));
        $value = $container->config->get(ConfigKey::ROUTES_CACHE_FILE, null);
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException(ConfigKey::ROUTES_CACHE_FILE . ' must be a non-empty path.');
        }
        $file = $value === null ? self::cacheFileFor($routes) : $paths->resolve($value);
        if (self::samePath($file, $routes)) {
            throw new InvalidArgumentException('Routes cache must not overwrite its source file.');
        }
        foreach ($reservedPaths as $reserved) {
            if (self::samePath($file, $reserved)) {
                throw new InvalidArgumentException('Routes cache conflicts with another application artifact: ' . $reserved);
            }
        }
        return $file;
    }

    private static function samePath(string $left, string $right): bool
    {
        return self::canonicalPath($left) === self::canonicalPath($right);
    }

    private static function canonicalPath(string $file): string
    {
        // An artifact directory may not exist during container assembly.
        // Resolve the nearest existing ancestor, not just the immediate parent.
        clearstatcache(true);
        $missing = [];
        $cursor = $file;
        while (($resolved = realpath($cursor)) === false) {
            if (is_link($cursor)) {
                throw new InvalidArgumentException('Cannot resolve route artifact path: ' . $cursor);
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new InvalidArgumentException('Cannot resolve route artifact path: ' . $file);
            }
            $missing[] = basename($cursor);
            $cursor = $parent;
        }

        foreach (array_reverse($missing) as $segment) {
            if ($segment === '.') { continue; }
            if ($segment === '..') { $resolved = dirname($resolved); continue; }
            $resolved = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
            // A preceding ".." can lead back into an existing directory.
            if (is_link($resolved)) {
                $target = realpath($resolved);
                if ($target === false) {
                    throw new InvalidArgumentException('Cannot resolve route artifact path: ' . $resolved);
                }
                $resolved = $target;
            }
        }
        $normalized = str_replace('\\', '/', $resolved);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    public static function cacheFileFor(string $routesFile): string
    {
        return dirname($routesFile) . DIRECTORY_SEPARATOR . pathinfo($routesFile, PATHINFO_FILENAME)
            . '.cache.' . (pathinfo($routesFile, PATHINFO_EXTENSION) ?: 'php');
    }
}
