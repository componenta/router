<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Resolver;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Middleware\Resolver\MiddlewareResolverInterface;
use Componenta\Http\Responder;
use Componenta\Http\Router\RouteHandler;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Resolves {@see RouteHandler} into PSR-15 middleware without interceptor support.
 *
 * Uses {@see CallableResolver} to map known interfaces to their entry-point methods
 * and wraps the result in {@see RouteHandlerMiddleware} for direct DI-based invocation.
 */
final class RouteHandlerResolver implements MiddlewareResolverInterface
{
    private CallableResolver $resolver;

    public function __construct(
        CallableExecutorInterface $executor,
        private readonly ?Responder $responder = null,
    ) {
        $this->resolver = new CallableResolver($executor);
    }

    public function resolve(mixed $middleware): ?MiddlewareInterface
    {
        if (!$middleware instanceof RouteHandler) {
            return null;
        }

        return new RouteHandlerMiddleware(
            $this->resolver->resolve($middleware->value),
            $this->resolver->resolver,
            $this->responder,
        );
    }
}
