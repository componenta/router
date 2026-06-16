<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Componenta\Http\Middleware\Exception\MiddlewareResolutionExceptionInterface;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\MatchResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route Dispatch Middleware
 *
 * Dispatches matched routes using MiddlewareFactory.
 * Works with MatchRouteMiddleware which attaches MatchResult to request.
 */
class DispatchRouteMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected readonly MiddlewareFactory $middlewareFactory,
    ) {}

    /**
     * @throws MiddlewareResolutionExceptionInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check for successful match
        $matchResult = MatchRouteMiddleware::getMatchResultFromRequest($request);

        if ($matchResult !== null) {
            return $this->resolveRouteMiddleware($matchResult)->process($request, $handler);
        }

        // No routing info - continue to next handler
        return $handler->handle($request);
    }

    public static function createFromContainer(ContainerInterface $container): self
    {
        return new self($container->get(MiddlewareFactory::class));
    }

    /**
     * @throws MiddlewareResolutionExceptionInterface
     */
    protected function resolveRouteMiddleware(MatchResult $matchResult): MiddlewareInterface
    {
        $pipe = $matchResult->middlewares?->with($matchResult->handler) ?? $matchResult->handler;

        return $this->middlewareFactory->createMiddleware($pipe);
    }
}
