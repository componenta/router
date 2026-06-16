<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Responder;
use Componenta\Http\Router\Resolver\RouteHandlerResolver;
use Psr\Container\ContainerInterface;

final readonly class RouteHandlerResolverFactory
{
    public function __invoke(ContainerInterface $container): RouteHandlerResolver
    {
        return new RouteHandlerResolver(
            executor: $container->get(CallableExecutorInterface::class),
            responder: $container->has(Responder::class) ? $container->get(Responder::class) : null,
        );
    }
}
