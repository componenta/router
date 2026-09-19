<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\Config\ContainerValue;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;

final readonly class DispatchRouteMiddlewareFactory
{
    public function __invoke(ContainerValue $container): DispatchRouteMiddleware
    {
        return new DispatchRouteMiddleware($container->get(MiddlewareFactory::class));
    }
}
