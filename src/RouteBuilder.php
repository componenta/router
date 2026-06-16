<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use LogicException;

/**
 * Fluent builder for RouteRecord
 */
final class RouteBuilder
{
    private mixed $handler = null;
    private array $methods = [];
    private array $tokens = [];
    private array $defaults = [];
    private ?string $group = null;

    private function __construct(
        private readonly string $name,
        private readonly string $path,
    ) {}

    public static function get(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::GET);
    }

    public static function post(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::POST);
    }

    public static function put(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::PUT);
    }

    public static function delete(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::DELETE);
    }

    public static function patch(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::PATCH);
    }

    public static function head(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::HEAD);
    }

    public static function options(string $name, string $path): self
    {
        return new self($name, $path)->methods(HttpMethod::OPTIONS);
    }

    public static function route(string $name, string $path): self
    {
        return new self($name, $path);
    }

    public function handler(mixed $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function methods(string ...$methods): self
    {
        $this->methods = array_merge($this->methods, $methods);
        return $this;
    }

    public function token(string $name, string $pattern): self
    {
        $this->tokens[$name] = $pattern;
        return $this;
    }

    public function tokens(array $tokens): self
    {
        $this->tokens = array_merge($this->tokens, $tokens);
        return $this;
    }

    public function default(string $name, mixed $value): self
    {
        $this->defaults[$name] = $value;
        return $this;
    }

    public function defaults(array $defaults): self
    {
        $this->defaults = array_merge($this->defaults, $defaults);
        return $this;
    }

    public function group(string $name): self
    {
        $this->group = $name;
        return $this;
    }

    public function build(): RouteRecord
    {
        if ($this->handler === null) {
            throw new LogicException("Route '$this->name' has no handler");
        }

        return new RouteRecord(
            name: $this->name,
            path: $this->path,
            handler: $this->handler,
            methods: $this->methods ?: [HttpMethod::GET, HttpMethod::POST, HttpMethod::PUT, HttpMethod::DELETE, HttpMethod::PATCH, HttpMethod::HEAD, HttpMethod::OPTIONS],
            tokens: $this->tokens,
            defaults: $this->defaults,
            group: $this->group,
        );
    }
}
