<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as BaseConfigKey;
use Componenta\Http\Router\ConfigProvider;
use Componenta\Http\Router\Contract\GeneratorInterface;
use Componenta\Http\Router\Contract\MatcherInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Componenta\Http\Router\Factory\RouterFactory;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Psr\Container\ContainerInterface;

final readonly class RouterFactoryRouteLocator implements RouteLocatorInterface
{
    public function __construct(private RouteCollectorInterface $routes) {}

    public function getRoutes(array $context = []): RouteCollectorInterface
    {
        return $this->routes;
    }
}

final readonly class RouterFactoryContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class RouterFactoryRouteCollector implements RouteCollectorInterface
{
    /** @var array<string, RouteRecord> */
    private array $routes = [];

    public function __construct(RouteRecord ...$routes)
    {
        foreach ($routes as $route) {
            $this->routes[$route->name] = $route;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
    }

    public function getRoute(string $name): RouteRecord
    {
        return $this->routes[$name] ?? throw new RouteNotRegisteredException($name);
    }

    public function toArray(): array
    {
        return $this->routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->routes);
    }
}

it('registers matcher and generator aliases to compatible implementations', function () {
    $config = (new ConfigProvider())();
    $aliases = $config[BaseConfigKey::DEPENDENCIES][BaseConfigKey::ALIASES];

    expect($aliases[MatcherInterface::class])->toBe(Routes::class)
        ->and($aliases[GeneratorInterface::class])->toBe(Routes::class)
        ->and(is_subclass_of($aliases[MatcherInterface::class], MatcherInterface::class))->toBeTrue()
        ->and(is_subclass_of($aliases[GeneratorInterface::class], GeneratorInterface::class))->toBeTrue();
});

it('builds a router without requiring located routes to implement matcher or generator contracts', function () {
    $routes = new RouterFactoryRouteCollector(
        RouteRecord::get('posts.show', '/posts/{id}', static fn () => null),
    );

    $router = (new RouterFactory())(new RouterFactoryContainer([
        RouteLocatorInterface::class => new RouterFactoryRouteLocator($routes),
        MatcherInterface::class => new Routes(),
        GeneratorInterface::class => new Routes(),
    ]));

    $match = $router->match('/posts/42', 'GET');
    $url = $router->generate('posts.show', ['id' => 42]);

    expect($match->name)->toBe('posts.show')
        ->and($match->parameters)->toBe(['id' => 42])
        ->and($url)->toBe('/posts/42');
});
