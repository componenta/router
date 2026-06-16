<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Componenta\Http\Middleware\Exception\MiddlewareResolutionExceptionInterface;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Router\MatchResult;
use Psr\Http\Server\MiddlewareInterface;

final class MemoizedDispatchRouteMiddleware extends DispatchRouteMiddleware
{
    /** @var array<string, MiddlewareInterface> */
    private array $resolvedRoutes = [];

    public function __construct(MiddlewareFactory $middlewareFactory)
    {
        parent::__construct($middlewareFactory);
    }

    /**
     * @throws MiddlewareResolutionExceptionInterface
     */
    protected function resolveRouteMiddleware(MatchResult $matchResult): MiddlewareInterface
    {
        return $this->resolvedRoutes[$matchResult->name] ??= parent::resolveRouteMiddleware($matchResult);
    }
}
