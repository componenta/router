<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('uses the same precedence and defaults through source, prepared and external collections', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('dynamic', '/items/{value}', 'Dynamic'));
    $source->addRoute(RouteRecord::get('static', '/items/all', 'Static', defaults: ['scope' => 'all']));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    $external = new class($source) implements RouteCollectorInterface {
        public function __construct(private Routes $routes) {}
        public function has(string $name): bool { return $this->routes->has($name); }
        public function getRoute(string $name): RouteRecord { return $this->routes->getRoute($name); }
        public function count(): int { return $this->routes->count(); }
        public function toArray(): array { return $this->routes->toArray(); }
        public function getIterator(): Traversable { return $this->routes->getIterator(); }
    };
    foreach ([$source, $prepared] as $matcher) {
        foreach ([$source, $prepared, $external] as $collection) {
            $match = $matcher->match($collection, '/items/all', 'GET');
            expect($match->name)->toBe('static')
                ->and($match->parameters)->toBe(['scope' => 'all']);
        }
    }
});

it('invalidates the source index after a route is registered', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('dynamic', '/items/{value}', 'Dynamic'));
    expect($source->match($source, '/items/all', 'GET')->name)->toBe('dynamic');
    $source->addRoute(RouteRecord::get('static', '/items/all', 'Static'));
    expect($source->match($source, '/items/all', 'GET')->name)->toBe('static');
});
