<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Router;
use Psr\Container\ContainerInterface;

final readonly class RouterFactory
{
    public function __invoke(ContainerInterface $container): Router
    {
        return new Router(
            routes: $container->get(RouteLocatorInterface::class)->getRoutes(),
            matcher: $container->get(MatcherInterface::class),
            generator: $container->get(GeneratorInterface::class),
        );
    }
}
