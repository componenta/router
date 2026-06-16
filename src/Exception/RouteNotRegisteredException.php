<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

/**
 * Thrown when trying to generate URL for unknown route
 */
final class RouteNotRegisteredException extends RouterException
{
    public function __construct(
        public readonly string $routeName,
    ) {
        parent::__construct("Route '$routeName' is not registered");
    }
}
