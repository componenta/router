<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

use Componenta\Http\Router\Exception\RouteNotRegisteredException;

/**
 * Contract for generating URLs from named routes.
 *
 * Implementations should generate URLs by substituting parameters
 * into route patterns and validating against defined constraints.
 */
interface GeneratorInterface
{
    /**
     * Generate a URL for a named route.
     *
     * @param RouteCollectorInterface $routes The route collection to search
     * @param string $name The route name (e.g., "users.show")
     * @param array<string, mixed> $parameters Parameters to substitute in the route pattern
     * @return string The generated URL (e.g., "/users/123")
     *
     * @throws RouteNotRegisteredException When route name is not found
     * @throws \InvalidArgumentException When required parameters are missing or invalid
     */
    public function generate(RouteCollectorInterface $routes, string $name, array $parameters = []): string;
}
