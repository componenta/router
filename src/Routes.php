<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Generator;

/**
 * Route collection for development.
 */
final class Routes implements RouteCollectorInterface, MatcherInterface, GeneratorInterface
{
    use ParameterCaster;

    /**
     * NUL-byte marker that {@see RouteGroup::addRoute()} prepends to a mutated
     * record's name to signal "this route has been processed by its group". On
     * the next call to {@see self::addRoute()}, the sentinel is stripped and the
     * record is stored without re-delegating, which is what breaks the
     * Routes -> Group -> Routes cycle. NUL is unreachable in any legitimate
     * route name, which makes it safe as an internal marker.
     */
    public const string PROCESSED_SENTINEL = "\0";

    /** @var array<string, RouteRecord> */
    private array $routes = [];

    private(set) GroupCollector $groups;

    /** @var array<string, array<string, RouteRecord>> */
    private array $staticRoutes = [];

    /** @var array<string, list<array{route: RouteRecord, regex: string, compiled: RouteCompileResult}>> */
    private array $dynamicRoutes = [];

    private bool $compiled = false;

    public function __construct(
        public CompilerInterface $compiler = new Compiler(),
    ) {
        $this->groups = new GroupCollector();
    }

    /**
     * Adds a route to the collection.
     *
     * If route has a group specified and that group exists, the route
     * is added through the group to apply its settings (prefix, middleware, etc.).
     *
     * @throws RouteAlreadyExistsException If route with this name already exists
     */
    public function addRoute(RouteRecord $route): self
    {
        // The NUL-byte sentinel is the marker {@see RouteGroup::addRoute()} prepends
        // to a record after applying its prefixes/middleware, signalling "already
        // processed - just store me". If we see it, strip and store directly. If we
        // don't see it and the route names a registered group, delegate; the group
        // will mutate the record (adding its prefixes) and re-enter this method,
        // this time with the sentinel set.
        if (str_contains($route->name, self::PROCESSED_SENTINEL)) {
            $route = $route->withName(str_replace(self::PROCESSED_SENTINEL, '', $route->name));
        } elseif ($route->group !== null && $this->groups->has($route->group)) {
            $this->groups->get($route->group)->addRoute($route);
            return $this;
        }

        if (isset($this->routes[$route->name])) {
            throw new RouteAlreadyExistsException($route->name);
        }

        $this->routes[$route->name] = $route;
        $this->compiled = false;

        return $this;
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
            return $this->createMatchResult($this->staticRoutes[$method][$uri], []);
        }

        $allowedMethods = $this->getAllowedMethodsForStaticUri($uri);

        $dynamicResult = $this->matchDynamic($uri, $method);
        if ($dynamicResult !== null) {
            return $dynamicResult;
        }

        if ($allowedMethods === []) {
            $allowedMethods = $this->getAllowedMethodsForDynamicUri($uri);
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException($uri, $method, $allowedMethods);
        }

        throw new RouteNotFoundException($uri, $method);
    }

    private function matchGeneric(RouteCollectorInterface $routes, string $uri, string $method): MatchResult
    {
        $method = strtoupper($method);
        $uri = '/' . ltrim($uri, '/');
        $allowedMethods = [];

        foreach ($routes as $route) {
            $compiled = $this->compiler->compile(
                $route->path,
                $route->tokens,
                $route->defaults
            );

            if (!$compiled->hasParameters()) {
                if ($route->path === $uri) {
                    if ($route->allow($method)) {
                        return new MatchResult($route->name, $route->handler, $route->middlewares, [], $route);
                    }
                    $allowedMethods = [...$allowedMethods, ...$route->methods];
                }
            } else {
                if (preg_match('#^' . $compiled->regex . '$#', $uri, $matches, PREG_UNMATCHED_AS_NULL)) {
                    if ($route->allow($method)) {
                        $params = $this->extractParameters($matches, $compiled);

                        return new MatchResult($route->name, $route->handler, $route->middlewares, $params, $route);
                    }
                    $allowedMethods = [...$allowedMethods, ...$route->methods];
                }
            }
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException($uri, $method, array_unique($allowedMethods));
        }

        throw new RouteNotFoundException($uri, $method);
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

        return $this->compiler->syntax->buildPath(
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

            foreach ($route->methods as $method) {
                if ($isStatic) {
                    $this->staticRoutes[$method][$route->path] = $route;
                } else {
                    $this->dynamicRoutes[$method][] = [
                        'route' => $route,
                        'regex' => '#^' . $compiled->regex . '$#',
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
            if (preg_match($data['regex'], $uri, $matches, PREG_UNMATCHED_AS_NULL)) {
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
            if (isset($matches[$name]) && $matches[$name] !== '') {
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
                if (preg_match($data['regex'], $uri)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }
}