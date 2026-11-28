<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use ReflectionReference;

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

    /** @var array<string, mixed> */
    public readonly array $parameters;

    public function __construct(
        public readonly string $name,
        public readonly RouteHandler $handler,
        public readonly ?MiddlewareGroup $middlewares,
        array $parameters,
        private readonly RouteCollectorInterface|RouteRecord $rr,
    ) {
        // A custom matcher can supply caller-owned reference slots. Preserve
        // the selected values without copying ordinary reference-free arrays.
        foreach ($parameters as $key => $_) {
            if (ReflectionReference::fromArrayElement($parameters, $key) !== null) {
                $parameters = array_map(static fn (mixed $value): mixed => $value, $parameters);
                break;
            }
        }
        $this->parameters = $parameters;
    }
}