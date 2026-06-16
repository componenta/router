<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\Contract\RouteCollectorInterface;

/**
 * Result of successful route matching
 *
 * Contains essential data for dispatching.
 * Full RouteRecord available via lazy property hook.
 */
final class MatchResult
{
    private ?RouteRecord $_route = null;

    public RouteRecord $route {
        get => $this->_route ??= $this->rr instanceof RouteRecord
            ? $this->rr
            : $this->rr->getRoute($this->name);
    }

    public function __construct(
        public readonly string $name,
        public readonly RouteHandler $handler,
        public readonly ?MiddlewareGroup $middlewares,
        public readonly array $parameters,
        private readonly RouteCollectorInterface|RouteRecord $rr,
    ) {}
}