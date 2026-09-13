<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Factory;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MemoizedDispatchRouteMiddleware;
use Psr\Container\ContainerInterface;

final readonly class DispatchRouteMiddlewareFactory
{
    public function __invoke(ContainerValue $container): DispatchRouteMiddleware
    {
        /** @var Config $config */
        $config = $container->config;

        $middlewareFactory = $container->get(MiddlewareFactory::class);

        if ($this->compiledPipelineEnabled($config)
            && (bool) $config->get(ConfigKey::CACHE_RESOLVED_ROUTE_MIDDLEWARE, true)
        ) {
            return new MemoizedDispatchRouteMiddleware($middlewareFactory);
        }

        return new DispatchRouteMiddleware($middlewareFactory);
    }

    private function compiledPipelineEnabled(Config $config): bool
    {
        return (bool) $config->get(ConfigKey::COMPILED_PIPELINE, true);
    }
}
