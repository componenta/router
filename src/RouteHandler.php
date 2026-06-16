<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

final readonly class RouteHandler
{
    public function __construct(
        public mixed $value
    ) {
    }
}