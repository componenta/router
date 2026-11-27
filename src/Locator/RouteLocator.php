<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Locator;

use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Routes;

/**
 * File-based route locator
 *
 * Loads route definitions from PHP configuration files.
 * Supports both development mode (dynamic loading) and production mode (cached).
 *
 * Cache mode is auto-detected if filename contains ".cache", or can be set explicitly.
 *
 * Development mode:
 * ```php
 * // routes.php
 * // @var Routes $routes
 * use Componenta\Http\Router\RouteRecord;
 *
 * $routes->addRoute(RouteRecord::get('home', '/', HomeController::class));
 * $routes->addRoute(RouteRecord::get('users.list', '/users', [UserController::class, 'index']));
 * $routes->addRoute(RouteRecord::get('users.show', '/users/[id]', [UserController::class, 'show']));
 * ```
 *
 * Production mode (cached):
 * ```php
 * // Auto-detected by filename
 * $locator = new RouteLocator('/path/to/routes.cache.php');
 *
 * // Or explicit
 * $locator = new RouteLocator('/path/to/compiled-routes.php', useCache: true);
 * ```
 *
 * With context variables:
 * ```php
 * $locator = new RouteLocator('/path/to/routes.php');
 * $routes = $locator->getRoutes([
 *     'middleware' => $middlewareStack,
 *     'prefix' => '/api/v1',
 * ]);
 * ```
 *
 * @see Routes For route registration API
 * @see CompiledRoutes For cached route collection
 * @see \Componenta\Http\Router\Cache\RouteCacheGenerator For generating cache files
 */
final class RouteLocator implements RouteLocatorInterface
{
    public bool $useCache;

    /**
     * @param string $filename Path to routes file (PHP config or compiled cache)
     * @param CompilerInterface $compiler Route pattern compiler
     * @param bool|null $useCache Cache mode: true = cached, false = dynamic, null = auto-detect by filename
     * @param string $routesVarName Local PHP variable name without the leading $ (source mode only)
     */
    public function __construct(
        public string $filename,
        private readonly CompilerInterface $compiler = new Compiler(),
        ?bool $useCache = null,
        public string $routesVarName = 'routes'
    ) {
        $this->useCache = $useCache ?? str_contains(basename($filename), '.cache');
    }

    /**
     * Load routes from the configured file.
     *
     * In cache mode: Returns CompiledRoutes loaded from cache file.
     * In normal mode: Includes the PHP file with Routes instance in scope.
     *
     * @param array<string, mixed> $context Variables to extract into route file scope.
     *                                       The `$routes` variable is automatically added.
     */
    public function getRoutes(array $context = []): Routes|CompiledRoutes
    {
        if ($this->useCache) {
            return CompiledRoutes::fromCache($this->filename);
        }

        self::validateRoutesVarName($this->routesVarName);
        $routes = new Routes($this->compiler);
        // Replace the slot without assigning through a caller-owned reference.
        $context = [$this->routesVarName => $routes] + $context;

        // The source may intentionally replace the route collection. Context
        // variables must not replace the loader's own arguments.
        return (static function (string $__routeFile, array $__routeContext, string $__routeVarName): Routes {
            extract($__routeContext, EXTR_SKIP);
            if (!is_file($__routeFile) || !is_readable($__routeFile)) {
                throw new \RuntimeException('Cannot read route source: ' . $__routeFile);
            }
            require $__routeFile;
            return ${$__routeVarName};
        })($this->filename, $context, $this->routesVarName);
    }

    private static function validateRoutesVarName(string $name): void
    {
        if (in_array($name, [
            '__routeFile', '__routeContext', '__routeVarName', 'this', 'GLOBALS',
            '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
        ], true)) {
            throw new \InvalidArgumentException('Reserved route collection variable name.');
        }

        // extract() silently skips invalid identifiers. PHP also permits
        // non-ASCII bytes in variable names; do not depend on PCRE limits.
        if ($name === '' || str_contains('0123456789', $name[0])) {
            throw new \InvalidArgumentException('Invalid route collection variable name.');
        }
        for ($i = 0, $length = strlen($name); $i < $length; ++$i) {
            $byte = $name[$i];
            if (ord($byte) < 128 && !str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_0123456789', $byte)) {
                throw new \InvalidArgumentException('Invalid route collection variable name.');
            }
        }
    }
}