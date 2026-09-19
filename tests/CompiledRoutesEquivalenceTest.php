<?php
declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('keeps the matched route after the supplied collection changes', function (string $path, string $uri): void {
    foreach ([false, true] as $compiled) {
        $original = RouteRecord::get('item', $path, 'OriginalHandler');
        $source = new Routes();
        $source->addRoute($original);
        $collector = new class($original) implements RouteCollectorInterface {
            public function __construct(public RouteRecord $route) {}
            public function has(string $name): bool { return $this->route->name === $name; }
            public function getRoute(string $name): RouteRecord
            {
                if (!$this->has($name)) { throw new RouteNotRegisteredException($name); }
                return $this->route;
            }
            public function count(): int { return 1; }
            public function toArray(): array { return [$this->route->name => $this->route]; }
            public function getIterator(): Traversable { yield $this->route->name => $this->route; }
        };
        $matcher = $compiled ? CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source)) : $source;

        $match = $matcher->match($collector, $uri, 'GET');
        $collector->route = RouteRecord::get('item', '/replacement', 'ReplacementHandler');

        expect($match->handler->value)->toBe('OriginalHandler')
            ->and($match->route->handler->value)->toBe('OriginalHandler')
            ->and($match->route->path)->toBe($path);
    }
})->with([
    'static route' => ['/items', '/items'],
    'dynamic route' => ['/items/{id}', '/items/1'],
]);

it('preserves numeric route names when reading a generated collection', function (string $name): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get($name, '/numeric', 'Handler'));
    $file = tempnam(sys_get_temp_dir(), 'numeric_route_');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }

    try {
        (new RouteCacheGenerator())->generate($source, $file);
        $compiled = CompiledRoutes::fromCache($file);
        foreach ([$source, $compiled] as $routes) {
            $records = array_values(iterator_to_array($routes));
            expect($records)->toHaveCount(1)
                ->and($records[0]->name)->toBe($name)
                ->and(array_values($routes->toArray())[0]->path)->toBe('/numeric')
                ->and($routes->match($routes, '/numeric', 'GET')->name)->toBe($name)
                ->and($routes->generate($routes, $name))->toBe('/numeric');
        }
        $loaded = CompiledRoutes::tryFromCache($file);
        expect($loaded)->toBeInstanceOf(CompiledRoutes::class)
            ->and($loaded->getRoute($name)->name)->toBe($name);
    } finally {
        unlink($file);
    }
})->with(['zero' => ['0'], 'positive' => ['42'], 'negative' => ['-1']]);

it('preserves handler and middleware identity between matches and public records', function (string $path, string $uri, bool $matchFirst): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', $path, 'Handler', ['Middleware']));
    $file = tempnam(sys_get_temp_dir(), 'route_identity_');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }

    try {
        (new RouteCacheGenerator())->generate($source, $file);
        $compiled = CompiledRoutes::tryFromCache($file);
        expect($compiled)->toBeInstanceOf(CompiledRoutes::class);
        foreach ([$source, $compiled] as $routes) {
            $first = $matchFirst ? $routes->match($routes, $uri, 'GET') : $routes->getRoute('item');
            $record = $routes->getRoute('item');
            $match = $routes->match($routes, $uri, 'GET');
            expect($match->route)->toBe($record)
                ->and($match->handler)->toBe($record->handler)->toBe($first->handler)
                ->and($match->middlewares)->toBe($record->middlewares)->toBe($first->middlewares);
        }
    } finally {
        unlink($file);
    }
})->with([
    'static record first' => ['/items', '/items', false],
    'static match first' => ['/items', '/items', true],
    'dynamic record first' => ['/items/{id}', '/items/17', false],
    'dynamic match first' => ['/items/{id}', '/items/17', true],
]);
