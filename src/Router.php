<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;

/**
 * Router facade.
 */
final readonly class Router
{
    public function __construct(
        public RouteCollectorInterface $routes,
        public MatcherInterface $matcher,
        public GeneratorInterface $generator,
    ) {}

    /**
     * Create Router from combined routes/matcher/generator.
     *
     * @param RouteCollectorInterface&MatcherInterface&GeneratorInterface $routes
     */
    public static function fromDnf(RouteCollectorInterface&MatcherInterface&GeneratorInterface $routes): self
    {
        return new self($routes, $routes, $routes);
    }

    /**
     * Match a URI and HTTP method to a registered route.
     *
     * @throws RouteNotFoundException When no route matches the URI
     * @throws MethodNotAllowedException When route exists but method is not allowed
     */
    public function match(string $uri, string $method): MatchResult
    {
        return $this->matcher->match($this->routes, $uri, $method);
    }

    /**
     * Generate a URL for a named route.
     *
     * @throws RouteNotRegisteredException When route name is not found
     * @throws \InvalidArgumentException When required parameters are missing or invalid
     */
    public function generate(string $name, array $parameters = []): string
    {
        return $this->generator->generate($this->routes, $name, $parameters);
    }
}