<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\Middleware\RouterExceptionHandlerInterface;
use Componenta\Http\Router\Router;
use Psr\Container\ContainerInterface;

final readonly class MatchRouteMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): MatchRouteMiddleware
    {
        return new MatchRouteMiddleware(
            router: $container->get(Router::class),
            exceptionHandler: $container->get(RouterExceptionHandlerInterface::class),
        );
    }
}
