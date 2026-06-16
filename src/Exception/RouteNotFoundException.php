<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

/**
 * Thrown when no route matches the request (404)
 */
final class RouteNotFoundException extends RouterException
{
    public function __construct(
        public readonly string $uri,
        public readonly string $method,
    ) {
        parent::__construct("No route found for {$method} {$uri}");
    }
}
