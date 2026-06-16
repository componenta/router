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
 * $routes->get('home', '/', HomeController::class);
 * $routes->get('users.list', '/users', [UserController::class, 'index']);
 * $routes->get('users.show', '/users/[id]', [UserController::class, 'show']);
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
 * @see RouteCacheGenerator For generating cache files
 */
final class RouteLocator implements RouteLocatorInterface
{
    public bool $useCache;

    /**
     * @param string $filename Path to routes file (PHP config or compiled cache)
     * @param CompilerInterface $compiler Route pattern compiler
     * @param bool|null $useCache Cache mode: true = cached, false = dynamic, null = auto-detect by filename
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

        $routes = new Routes($this->compiler);
        $context[$this->routesVarName] = $routes;

        extract($context);
        include $this->filename;

        return $routes;
    }
}