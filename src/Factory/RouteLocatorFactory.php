<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\Stdlib\PathResolverInterface;
use Componenta\Config\Config;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Locator\RouteLocator;
use Psr\Container\ContainerInterface;

final readonly class RouteLocatorFactory
{
    /**
     * Resolution rules:
     *  - **Production with `*.cache.php` next to `ROUTES_FILE`**:
     *    use the cached file directly. {@see RouteLocator} auto-detects
     *    cache mode by `.cache` substring and loads {@see \Componenta\Http\Router\CompiledRoutes}
     *    instead of executing route registration.
     *  - **Without cache**: bare {@see RouteLocator} on the static routes file.
     *    Attribute-driven routes belong to `componenta/router-app`.
     */
    public function __invoke(ContainerInterface $container): RouteLocator
    {
        /** @var Config $config */
        $config = $container->get(ConfigKey::CONFIG);
        $paths = $container->get(PathResolverInterface::class);

        $routesFile = $paths->resolve($config->get(ConfigKey::ROUTES_FILE));
        $compiler   = $container->get(CompilerInterface::class);
        $target = $routesFile;

        if ($config->environment->match('APP_ENV', 'production') && $this->compiledPipelineEnabled($config)) {
            $cacheFile = $config->get(ConfigKey::ROUTES_CACHE_FILE, default: null);
            $cacheFile = is_string($cacheFile)
                ? $paths->resolve($cacheFile)
                : self::cacheFileFor($routesFile);
            $target    = is_file($cacheFile) ? $cacheFile : $routesFile;
        }

        return new RouteLocator($target, $compiler);
    }

    private function compiledPipelineEnabled(Config $config): bool
    {
        return (bool) $config->get(ConfigKey::COMPILED_PIPELINE, true);
    }

    /**
     * Derives the cache-file path from the configured routes file by
     * inserting `.cache` before the extension (`routes.php` -> `routes.cache.php`).
     */
    public static function cacheFileFor(string $routesFile): string
    {
        $dir  = dirname($routesFile);
        $base = pathinfo($routesFile, PATHINFO_FILENAME);
        $ext  = pathinfo($routesFile, PATHINFO_EXTENSION);

        return $dir . DIRECTORY_SEPARATOR . $base . '.cache.' . ($ext === '' ? 'php' : $ext);
    }
}
