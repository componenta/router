<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use InvalidArgumentException;
use Componenta\Http\Middleware\MiddlewareGroup;

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
     * @param list<string>|null $methods HTTP methods (normalized to uppercase)
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
        $this->path = self::normalizePath($path);
        $this->methods = self::normalizeMethods($methods ?? [HttpMethod::GET]);
        $this->tokens = self::validateTokens($tokens);
        $this->defaults = self::validateDefaults($defaults);

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
        return in_array(strtoupper($method), $this->methods, true);
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
            self::normalizePath($prefix) . $this->path,
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

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            path: $data['path'],
            handler: $data['handler'],
            methods: $data['methods'] ?? null,
            middlewares: $data['middlewares'] ?? [],
            tokens: $data['tokens'] ?? [],
            defaults: $data['defaults'] ?? [],
            group: $data['group'] ?? null,
        );
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        if (str_contains($path, '//')) {
            $path = preg_replace('#/+#', '/', $path) ?? $path;
        }

        return $path;
    }

    /**
     * @param list<string> $methods
     * @return list<string>
     */
    private static function normalizeMethods(array $methods): array
    {
        if ($methods === []) {
            throw new InvalidArgumentException('Route must have at least one HTTP method');
        }

        $normalized = [];

        foreach ($methods as $method) {
            if (!is_string($method)) {
                throw new InvalidArgumentException('HTTP method must be a string');
            }

            $upper = strtoupper(trim($method));

            if ($upper === '') {
                throw new InvalidArgumentException('HTTP method cannot be empty');
            }

            if (!in_array($upper, $normalized, true)) {
                $normalized[] = $upper;
            }
        }

        return $normalized;
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

    /**
     * @param array<string, string> $tokens
     * @return array<string, string>
     */
    private static function validateTokens(array $tokens): array
    {
        foreach ($tokens as $name => $pattern) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Token name must be a non-empty string');
            }

            if (!is_string($pattern)) {
                throw new InvalidArgumentException("Token pattern for '$name' must be a string");
            }

            // Validate regex pattern
            if (@preg_match('#^' . $pattern . '$#', '') === false) {
                throw new InvalidArgumentException("Invalid regex pattern for token '$name': $pattern");
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private static function validateDefaults(array $defaults): array
    {
        foreach ($defaults as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Default parameter name must be a non-empty string');
            }

            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Default value for '$name' must be scalar or null");
            }
        }

        return $defaults;
    }
}