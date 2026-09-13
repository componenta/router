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

    public static function cacheFile(ContainerValue $container): string
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);
        $routes = $paths->resolve($container->config->string(ConfigKey::ROUTES_FILE));
        $value = $container->config->get(ConfigKey::ROUTES_CACHE_FILE, null);
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException(ConfigKey::ROUTES_CACHE_FILE . ' must be a non-empty path.');
        }
        $file = $value === null ? self::cacheFileFor($routes) : $paths->resolve($value);
        if (strtolower(str_replace('\\', '/', $file)) === strtolower(str_replace('\\', '/', $routes))) {
            throw new InvalidArgumentException('Routes cache must not overwrite its source file.');
        }
        return $file;
    }

    public static function cacheFileFor(string $routesFile): string
    {
        return dirname($routesFile) . DIRECTORY_SEPARATOR . pathinfo($routesFile, PATHINFO_FILENAME)
            . '.cache.' . (pathinfo($routesFile, PATHINFO_EXTENSION) ?: 'php');
    }
}
