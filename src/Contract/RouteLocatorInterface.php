<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

/**
 * Contract for locating and loading route definitions.
 *
 * Implementations can load routes from various sources:
 * - PHP configuration files (RouteLocator)
 * - Class attributes (AttributeRouteLocator)
 * - Compiled cache files
 * - Databases or other storage
 *
 * Locators can be decorated to combine multiple sources.
 */
interface RouteLocatorInterface
{
    /**
     * Load and return the route collection.
     *
     * @param array<string, mixed> $context Variables available in route file scope
     * @return RouteCollectorInterface The loaded routes
     */
    public function getRoutes(array $context = []): RouteCollectorInterface;
}