<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Router\Internal\RouteDefinition;

/**
 * Immutable route definition
 */
final readonly class RouteRecord
{
    public string $path;

    /** @var list<string> */
    public array $methods;

    /** @var array<string, string> */
    public array $tokens;

    /** @var array<string, mixed> */
    public array $defaults;

    public ?MiddlewareGroup $middlewares;

    public RouteHandler $handler;

    /**
     * @param list<string>|null $methods HTTP method tokens (case-sensitive)
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public function __construct(
        public string $name,
        string $path,
        mixed $handler,
        ?array $methods = null,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
        public ?string $group = null,
    ) {
        RouteDefinition::validateName($name);
        $this->path = RouteDefinition::normalizePath($path);
        $this->methods = RouteDefinition::normalizeMethods($methods ?? [HttpMethod::GET]);
        $this->tokens = RouteDefinition::tokens($tokens);
        $this->defaults = RouteDefinition::defaults($defaults);

        $this->handler = $handler instanceof RouteHandler
            ? $handler
            : new RouteHandler($handler);

        $this->middlewares = self::normalizeMiddlewares($middlewares);
    }

    /**
     * Check if route allows given HTTP method.
     */
    public function allow(string $method): bool
    {
        return in_array(HttpMethod::normalize($method), $this->methods, true);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function get(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::GET], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function post(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::POST], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function put(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::PUT], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function delete(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::DELETE], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function patch(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::PATCH], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function head(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::HEAD], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function options(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [HttpMethod::OPTIONS], $middlewares, $tokens, $defaults);
    }

    /**
     * @param array<string, string> $tokens Parameter patterns
     * @param array<string, mixed> $defaults Default parameter values
     */
    public static function any(
        string $name,
        string $path,
        mixed $handler,
        MiddlewareGroup|array|string $middlewares = [],
        array $tokens = [],
        array $defaults = [],
    ): self {
        return new self($name, $path, $handler, [
            HttpMethod::GET,
            HttpMethod::POST,
            HttpMethod::PUT,
            HttpMethod::DELETE,
            HttpMethod::PATCH,
            HttpMethod::HEAD,
            HttpMethod::OPTIONS,
        ], $middlewares, $tokens, $defaults);
    }

    public function withPrefix(string $prefix): self
    {
        return new self(
            $this->name,
            RouteDefinition::normalizePath($prefix) . $this->path,
            $this->handler,
            $this->methods,
            $this->middlewares ?? [],
            $this->tokens,
            $this->defaults,
            $this->group,
        );
    }

    public function withMiddlewares(MiddlewareGroup|array|string $middlewares): self
    {
        return new self(
            $this->name,
            $this->path,
            $this->handler,
            $this->methods,
            $middlewares,
            $this->tokens,
            $this->defaults,
            $this->group,
        );
    }

    public function withTokens(array $tokens): self
    {
        return new self(
            $this->name,
            $this->path,
            $this->handler,
            $this->methods,
            $this->middlewares ?? [],
            $tokens,
            $this->defaults,
            $this->group,
        );
    }

    public function withDefaults(array $defaults): self
    {
        return new self(
            $this->name,
            $this->path,
            $this->handler,
            $this->methods,
            $this->middlewares ?? [],
            $this->tokens,
            $defaults,
            $this->group,
        );
    }

    public function withGroup(?string $group): self
    {
        return new self(
            $this->name,
            $this->path,
            $this->handler,
            $this->methods,
            $this->middlewares ?? [],
            $this->tokens,
            $this->defaults,
            $group,
        );
    }

    public function withMethods(array $methods): self
    {
        return new self(
            $this->name,
            $this->path,
            $this->handler,
            $methods,
            $this->middlewares ?? [],
            $this->tokens,
            $this->defaults,
            $this->group,
        );
    }

    public function withHandler(mixed $handler): self
    {
        return new self(
            $this->name,
            $this->path,
            $handler,
            $this->methods,
            $this->middlewares ?? [],
            $this->tokens,
            $this->defaults,
            $this->group,
        );
    }

    public function withName(string $name): self
    {
        return new self(
            $name,
            $this->path,
            $this->handler,
            $this->methods,
            $this->middlewares ?? [],
            $this->tokens,
            $this->defaults,
            $this->group,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'handler' => $this->handler->value,
            'methods' => $this->methods,
            'middlewares' => $this->middlewares?->toArray() ?? [],
            'tokens' => $this->tokens,
            'defaults' => $this->defaults,
            'group' => $this->group,
        ];
    }

    /** Restore the raw handler value emitted by toArray(), not an existing wrapper. */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            path: $data['path'],
            handler: new RouteHandler($data['handler']),
            methods: $data['methods'] ?? null,
            middlewares: $data['middlewares'] ?? [],
            tokens: $data['tokens'] ?? [],
            defaults: $data['defaults'] ?? [],
            group: $data['group'] ?? null,
        );
    }

    private static function normalizeMiddlewares(MiddlewareGroup|array|string $middlewares): ?MiddlewareGroup
    {
        if ($middlewares instanceof MiddlewareGroup) {
            return $middlewares;
        }

        $middlewares = (array) $middlewares;

        if ($middlewares === []) {
            return null;
        }

        return MiddlewareGroup::fromArray($middlewares);
    }
}
