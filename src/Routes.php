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
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\Internal\RouteRegex;
use Generator;

/**
 * Route collection for development.
 */
final class Routes implements RouteCollectorInterface, MatcherInterface, GeneratorInterface
{
    use ParameterCaster;

    /** @var array<string, RouteRecord> */
    private array $routes = [];

    private(set) GroupCollector $groups;

    /** @var array<string, array<string, RouteRecord>> */
    private array $staticRoutes = [];

    /** @var array<string, list<array{route: RouteRecord, regex: string, compiled: RouteCompileResult}>> */
    private array $dynamicRoutes = [];

    private bool $compiled = false;

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
        $this->compiled = false;
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

        $this->ensureCompiled();

        $method = strtoupper($method);
        $uri = '/' . ltrim($uri, '/');

        if (isset($this->staticRoutes[$method][$uri])) {
            return $this->createMatchResult($this->staticRoutes[$method][$uri], $this->staticRoutes[$method][$uri]->defaults);
        }

        $dynamicResult = $this->matchDynamic($uri, $method);
        if ($dynamicResult !== null) {
            return $dynamicResult;
        }

        $allowedMethods = array_values(array_unique([...$this->getAllowedMethodsForStaticUri($uri), ...$this->getAllowedMethodsForDynamicUri($uri)]));
        sort($allowedMethods, SORT_STRING);

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException($uri, $method, $allowedMethods);
        }

        throw new RouteNotFoundException($uri, $method);
    }

    private function matchGeneric(RouteCollectorInterface $routes, string $uri, string $method): MatchResult
    {
        $source = self::fromResolved($routes, $this->compiler, $this->syntax);
        return $source->match($source, $uri, $method);
    }

    public function generate(RouteCollectorInterface $routes, string $name, array $parameters = []): string
    {
        if ($routes !== $this && $routes instanceof GeneratorInterface) {
            return $routes->generate($routes, $name, $parameters);
        }

        $route = $routes->getRoute($name);
        $compiled = $this->compiler->compile(
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

    private function ensureCompiled(): void
    {
        if ($this->compiled) {
            return;
        }

        $this->staticRoutes = [];
        $this->dynamicRoutes = [];

        foreach ($this->routes as $route) {
            $compiled = $this->compiler->compile(
                $route->path,
                $route->tokens,
                $route->defaults
            );
            $isStatic = !$compiled->hasParameters();
            $regex = RouteRegex::anchored($compiled->regex);
            if (!$isStatic) {
                RouteRegex::validate($regex);
            }

            foreach ($route->methods as $method) {
                if ($isStatic) {
                    $previous = $this->staticRoutes[$method][$route->path] ?? null;
                    if ($previous !== null) {
                        throw new RouteConflictException($method, $route->path, $previous->name, $route->name);
                    }
                    $this->staticRoutes[$method][$route->path] = $route;
                } else {
                    $this->dynamicRoutes[$method][] = [
                        'route' => $route,
                        'regex' => $regex,
                        'compiled' => $compiled,
                    ];
                }
            }
        }

        $this->compiled = true;
    }

    private function matchDynamic(string $uri, string $method): ?MatchResult
    {
        if (!isset($this->dynamicRoutes[$method])) {
            return null;
        }

        foreach ($this->dynamicRoutes[$method] as $data) {
            if (RouteRegex::match($data['regex'], $uri, $matches)) {
                $params = $this->extractParameters($matches, $data['compiled']);

                return $this->createMatchResult($data['route'], $params);
            }
        }

        return null;
    }

    private function extractParameters(array $matches, RouteCompileResult $compiled): array
    {
        $params = $compiled->defaults;

        foreach ($compiled->parameterNames() as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $this->castParameter($matches[$name]);
            }
        }

        return $params;
    }

    private function createMatchResult(RouteRecord $route, array $params): MatchResult
    {
        return new MatchResult($route->name, $route->handler, $route->middlewares, $params, $route);
    }

    /** @return list<string> */
    private function getAllowedMethodsForStaticUri(string $uri): array
    {
        $allowed = [];
        foreach ($this->staticRoutes as $method => $routes) {
            if (isset($routes[$uri])) {
                $allowed[] = $method;
            }
        }

        return $allowed;
    }

    /** @return list<string> */
    private function getAllowedMethodsForDynamicUri(string $uri): array
    {
        $allowed = [];
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $data) {
                if (RouteRegex::match($data['regex'], $uri)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }
}
