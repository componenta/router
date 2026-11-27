<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Responder;
use Componenta\Http\Router\Resolver\RouteHandlerResolver;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final readonly class RouteHandlerResolverFactory
{
    public function __invoke(ContainerInterface $container): RouteHandlerResolver
    {
        $responder = null;
        if ($container->has(Responder::class)) {
            // An autowiring candidate need not have its HTTP factories configured.
            // PSR results bypass normalization and must not resolve this dependency.
            $reflection = new ReflectionClass(Responder::class);
            $responder = $reflection->newLazyProxy(static function () use ($container, $reflection): Responder {
                // Native proxies require an initialized backing object; the
                // application's responder registration may itself already be lazy.
                return $reflection->initializeLazyObject($container->get(Responder::class));
            });
        }

        return new RouteHandlerResolver(
            executor: $container->get(CallableExecutorInterface::class),
            responder: $responder,
        );
    }
}
