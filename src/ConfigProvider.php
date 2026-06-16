<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Factory\DispatchRouteMiddlewareFactory;
use Componenta\Http\Router\Factory\MatchRouteMiddlewareFactory;
use Componenta\Http\Router\Factory\RouteHandlerResolverFactory;
use Componenta\Http\Router\Factory\RouteLocatorFactory;
use Componenta\Http\Router\Factory\RouterFactory;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\Middleware\RouterExceptionHandlerInterface;
use Componenta\Http\Router\Middleware\ThrowingRouterExceptionHandler;
use Componenta\Http\Router\Resolver\RouteHandlerResolver;

final class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            RouteLocatorInterface::class => RouteLocatorFactory::class,
            Router::class => RouterFactory::class,
            MatchRouteMiddleware::class => MatchRouteMiddlewareFactory::class,
            DispatchRouteMiddleware::class => DispatchRouteMiddlewareFactory::class,
            RouteHandlerResolver::class => RouteHandlerResolverFactory::class,
        ];
    }

    protected function getInvokables(): array
    {
        return [
            Compiler::class,
            ThrowingRouterExceptionHandler::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            CompilerInterface::class => Compiler::class,
            RouterExceptionHandlerInterface::class => ThrowingRouterExceptionHandler::class,
            MatcherInterface::class => Routes::class,
            GeneratorInterface::class => Routes::class,
        ];
    }

}
