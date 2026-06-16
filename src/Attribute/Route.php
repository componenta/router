<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Attribute;

/**
 * Route attribute for controller methods and invokable classes
 *
 * Usage:
 * ```php
 * // On method
 * #[Route('users.show', '/users/[id]', 'GET', tokens: ['id' => '\d+'])]
 * public function show(int $id): Response { }
 *
 * // On invokable class
 * #[Route('home', '/', 'GET')]
 * class HomeController {
 *     public function __invoke(): Response { }
 * }
 *
 * // With inline constraints
 * #[Route('posts.list', '/posts/[?page:\d+=1]', 'GET')]
 * public function list(int $page): Response { }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
readonly class Route
{
    /** @var list<string> */
    public array $methods;
    public array $middlewares;

    /**
     * @param string $name Unique route name
     * @param string $path URL pattern (supports [param], [?param], [param:\d+], [?param=default])
     * @param array<string>|string $methods HTTP methods (string: 'GET', 'GET|POST'; array: ['GET', 'POST'])
     * @param array<string, string> $tokens Parameter regex constraints
     * @param array<string, mixed> $defaults Default parameter values
     * @param string|null $group Route group name
     * @param int $priority Route priority (higher = matched first)
     */
    public function __construct(
        public string $name,
        public string $path,
        array|string $methods = ['GET'],
        array|string $middlewares = [],
        public array $tokens = [],
        public array $defaults = [],
        public ?string $group = null,
        public int $priority = 0,
    ) {
        $this->middlewares = (array) $middlewares;
        $this->methods = is_string($methods)
            ? (str_contains($methods, '|') ? explode('|', $methods) : [$methods])
            : $methods;
    }
}
