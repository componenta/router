<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Componenta\Http\Router\Internal\SourceRouteIndex;
use Generator;

/**
 * Route collection for development.
 */
final class Routes implements RouteCollectorInterface, MatcherInterface, GeneratorInterface
{
    /** @var array<string, RouteRecord> */
    private array $routes = [];

    private(set) GroupCollector $groups;

    private ?SourceRouteIndex $index = null;

    public function __construct(
        public readonly CompilerInterface $compiler = new Compiler(),
        public readonly ?SyntaxParserInterface $syntax = null,
    ) {
        $this->groups = new GroupCollector();
    }

    /** Register a source definition, applying its named group exactly once. */
    public function addRoute(RouteRecord $route): self
    {
        $resolved = $route->group === null
            ? $route
            : $this->groups->get($route->group)->apply($route);
        $this->store($resolved);
        return $this;
    }

    /**
     * Reconstruct a collection from effective route records, not source definitions.
     * @internal Used by matchers and cache restoration. Groups have already been applied.
     * @param iterable<RouteRecord> $records
     */
    public static function fromResolved(
        iterable $records,
        CompilerInterface $compiler = new Compiler(),
        ?SyntaxParserInterface $syntax = null,
    ): self {
        $routes = new self($compiler, $syntax);
        foreach ($records as $record) {
            $routes->store($record);
        }
        return $routes;
    }

    private function store(RouteRecord $route): void
    {
        if (isset($this->routes[$route->name])) {
            throw new RouteAlreadyExistsException($route->name);
        }
        $this->routes[$route->name] = $route;
        $this->index = null;
    }

    /**
     * Creates and registers a new route group.
     *
     * @param string $name Group name (used as route name prefix)
     * @param string $prefix Path prefix for all routes in the group
     * @param MiddlewareGroup|array|string $middleware Middleware applied to all routes
     * @param array<string, string> $tokens Parameter patterns for routes
     * @param array<string, mixed> $defaults Default parameter values
     */
    public function group(
        string $name,
        string $prefix,
        MiddlewareGroup|array|string $middleware = [],
        array $tokens = [],
        array $defaults = [],
    ): RouteGroup {
        $group = new RouteGroup(
            routes: $this,
            name: $name,
            prefix: $prefix,
            middleware: $middleware,
            tokens: $tokens,
            defaults: $defaults,
        );

        $this->groups->add($group);

        return $group;
    }

    public function match(RouteCollectorInterface $routes, string $uri, string $method): MatchResult
    {
        if ($routes !== $this) {
            if ($routes instanceof MatcherInterface) {
                return $routes->match($routes, $uri, $method);
            }

            return $this->matchGeneric($routes, $uri, $method);
        }

        return ($this->index ??= new SourceRouteIndex($this->routes, $this->compiler))->match($uri, $method);
    }

    private function matchGeneric(RouteCollectorInterface $routes, string $uri, string $method): MatchResult
    {
        return (new SourceRouteIndex($routes, $this->compiler))->match($uri, $method);
    }

    public function generate(RouteCollectorInterface $routes, string $name, array $parameters = []): string
    {
        if ($routes !== $this && $routes instanceof GeneratorInterface) {
            return $routes->generate($routes, $name, $parameters);
        }

        $route = $routes->getRoute($name);
        $compiled = ($routes === $this ? $this->index?->pattern($name) : null) ?? $this->compiler->compile(
            $route->path,
            $route->tokens,
            $route->defaults
        );

        $syntax = $this->syntax ?? ($this->compiler instanceof Compiler
            ? $this->compiler->syntax
            : new CompositeSyntax());

        return $syntax->buildPath(
            $route->path,
            $parameters + $compiled->defaults,
            $compiled->tokens,
            $compiled->optionalParameters,
            $name
        );
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
    }

    public function getRoute(string $name): RouteRecord
    {
        if (!isset($this->routes[$name])) {
            throw new RouteNotRegisteredException($name);
        }

        return $this->routes[$name];
    }

    public function count(): int
    {
        return count($this->routes);
    }

    public function getIterator(): Generator
    {
        foreach ($this->routes as $name => $route) {
            yield $name => $route;
        }
    }

    public function toArray(): array
    {
        return $this->routes;
    }
}
