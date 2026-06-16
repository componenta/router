<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

final class RouteAlreadyExistsException extends RouterException
{
    public function __construct(
        public readonly string $routeName,
    ) {
        parent::__construct("Route '{$routeName}' is already registered.");
    }
}
