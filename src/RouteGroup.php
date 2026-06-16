<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;

/**
 * Route group that registers routes to the parent collection.
 */
final readonly class RouteGroup
{
    public ?MiddlewareGroup $middleware;

    private(set) string $fullName;
    private string $fullPrefix;

    /** @var array<string, string> */
    private array $inheritedTokens;

    /** @var array<string, mixed> */
    private array $inheritedDefaults;

    /** @var list<mixed> */
    private array $inheritedMiddleware;

    /**
     * @param Routes $routes Parent routes collection
     * @param string $name Group name
     * @param string $prefix Path prefix for all routes in the group
     * @param MiddlewareGroup|array|string $middleware Middleware applied to all routes
     * @param array<string, string> $tokens Parameter patterns for routes
     * @param array<string, mixed> $defaults Default parameter values
     * @param self|null $parent Parent group for nested groups
     */
    public function __construct(
        private Routes $routes,
        public string $name,
        public string $prefix,
        MiddlewareGroup|array|string $middleware = [],
        public array $tokens = [],
        public array $defaults = [],
        public ?self $parent = null,
    ) {
        $this->middleware = match (true) {
            $middleware instanceof MiddlewareGroup => $middleware,
            is_string($middleware) => new MiddlewareGroup($middleware),
            $middleware === [] => null,
            default => MiddlewareGroup::fromArray($middleware),
        };

        // Precompute inherited values
        $this->fullName = $parent !== null
            ? "$parent->fullName.$name"
            : $name;

        $parentPrefix = $parent?->fullPrefix ?? '';
        $normalizedPrefix = rtrim($parentPrefix, '/') . '/' . ltrim($prefix, '/');
        $this->fullPrefix = $normalizedPrefix === '/' ? '' : rtrim($normalizedPrefix, '/');

        $this->inheritedTokens = [...($parent?->inheritedTokens ?? []), ...$tokens];
        $this->inheritedDefaults = [...($parent?->inheritedDefaults ?? []), ...$defaults];
        $this->inheritedMiddleware = [
            ...($parent?->inheritedMiddleware ?? []),
            ...($this->middleware?->toArray() ?? []),
        ];
    }

    /**
     * Adds a route to the group.
     *
     * Applies group settings: name prefix, path prefix, tokens, defaults,
     * and merges middleware from group with route middleware. The mutated
     * name is tagged with {@see Routes::PROCESSED_SENTINEL} so the parent
     * collection stores the record without re-delegating.
     */
    public function addRoute(RouteRecord $route): self
    {
        // Merge: group middleware + route middleware
        $routeMiddleware = $route->middlewares?->toArray() ?? [];
        $allMiddleware = [...$this->inheritedMiddleware, ...$routeMiddleware];

        $mutated = new RouteRecord(
            name: Routes::PROCESSED_SENTINEL . "$this->fullName.$route->name",
            path: $this->fullPrefix . $route->path,
            handler: $route->handler,
            methods: $route->methods,
            middlewares: $allMiddleware,
            tokens: [...$this->inheritedTokens, ...$route->tokens],
            defaults: [...$this->inheritedDefaults, ...$route->defaults],
            group: $this->fullName,
        );

        $this->routes->addRoute($mutated);

        return $this;
    }

    public function get(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::get($name, $path, $handler));
    }

    public function post(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::post($name, $path, $handler));
    }

    public function put(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::put($name, $path, $handler));
    }

    public function delete(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::delete($name, $path, $handler));
    }

    public function patch(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::patch($name, $path, $handler));
    }

    public function head(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::head($name, $path, $handler));
    }

    public function options(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::options($name, $path, $handler));
    }

    public function any(string $name, string $path, mixed $handler): self
    {
        return $this->addRoute(RouteRecord::any($name, $path, $handler));
    }

    /**
     * Creates a nested group and registers it in the parent collection so that
     * routes referencing it by `fullName` (e.g. `'api.admin'`) are delegated
     * correctly. Top-level groups self-register in {@see Routes::group()};
     * nested groups must do the same here, or the group lookup in
     * {@see Routes::addRoute()} returns false and the route silently bypasses
     * the group's prefix and middleware.
     */
    public function group(
        string $name,
        string $prefix,
        MiddlewareGroup|array|string $middleware = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        $child = new self(
            routes: $this->routes,
            name: $name,
            prefix: $prefix,
            middleware: $middleware,
            tokens: $tokens,
            defaults: $defaults,
            parent: $this,
        );

        $this->routes->groups->add($child);

        return $child;
    }
}