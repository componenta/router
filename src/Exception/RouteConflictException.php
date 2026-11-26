<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

final class RouteConflictException extends RouterException
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $firstRoute,
        public readonly string $secondRoute,
    ) {
        parent::__construct(sprintf('Conflicting %s routes "%s" and "%s" at "%s".', $method, $firstRoute, $secondRoute, $path));
    }
}
