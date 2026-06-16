<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;

/**
 * Contract for matching incoming requests to routes.
 *
 * Implementations should match a URI and HTTP method against provided routes
 * and return a MatchResult containing the matched route information.
 */
interface MatcherInterface
{
    /**
     * Match a URI and HTTP method to a registered route.
     *
     * @param RouteCollectorInterface $routes The route collection to match against
     * @param string $uri The request URI to match (e.g., "/users/123")
     * @param string $method The HTTP method (e.g., "GET", "POST")
     * @return MatchResult The matched route result with extracted parameters
     *
     * @throws RouteNotFoundException When no route matches the URI
     * @throws MethodNotAllowedException When route exists but method is not allowed
     */
    public function match(RouteCollectorInterface $routes, string $uri, string $method): MatchResult;
}
