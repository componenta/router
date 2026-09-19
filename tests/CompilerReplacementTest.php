<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('uses a replacement compiler before the first match', function (bool $cached): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemHandler'));
    $routes = $cached ? CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source)) : $source;
    $routes->compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);

    expect($routes->generate($routes, 'item', ['id' => 'ABC']))->toBe('/items/ABC')
        ->and($routes->match($routes, '/items/ABC', 'GET')->parameters)->toBe(['id' => 'ABC'])
        ->and(fn () => $routes->generate($routes, 'item', ['id' => 42]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $routes->match($routes, '/items/42', 'GET'))
        ->toThrow(\Componenta\Http\Router\Exception\RouteNotFoundException::class);

    $routes->compiler = new Compiler(defaultPatterns: ['id' => '[a-z]+']);
    expect($routes->generate($routes, 'item', ['id' => 'abc']))->toBe('/items/abc')
        ->and($routes->match($routes, '/items/XYZ', 'GET')->parameters)->toBe(['id' => 'XYZ']);
})->with(['source' => false, 'cached' => true]);

it('distinguishes explicit generation syntax from the compiler syntax after replacement', function (bool $cached, bool $explicit, bool $external): void {
    $source = new Routes(syntax: $explicit ? new \Componenta\Http\Router\Syntax\CompositeSyntax() : null);
    $source->addRoute(RouteRecord::get('item', '/items/[id]', 'ItemHandler'));
    $routes = $cached ? CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source)) : $source;
    $routes->compiler = new Compiler(new \Componenta\Http\Router\Syntax\CurlySyntax(), ['id' => '[A-Z]+']);
    $collector = $external ? new class($source) implements \Componenta\Http\Router\Contract\RouteCollectorInterface {
        public function __construct(private Routes $routes) {}
        public function has(string $name): bool { return $this->routes->has($name); }
        public function getRoute(string $name): RouteRecord { return $this->routes->getRoute($name); }
        public function toArray(): array { return $this->routes->toArray(); }
        public function count(): int { return $this->routes->count(); }
        public function getIterator(): Traversable { return $this->routes->getIterator(); }
    } : $routes;

    expect($routes->generate($collector, 'item', ['id' => 'ABC']))->toBe($explicit ? '/items/ABC' : '/items/[id]');
})->with(['source' => false, 'cached' => true])->with(['automatic syntax' => false, 'explicit syntax' => true])
    ->with(['own collection' => false, 'external collection' => true]);

it('preserves an already initialized matcher when its compiler is replaced', function (string $mode): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemHandler'));
    $data = (new RouteCacheGenerator())->compile($source);
    if ($mode === 'legacy source matching') { $data['version'] = 4; }
    $routes = $mode === 'source' ? $source : CompiledRoutes::fromArray($data);
    expect($routes->match($routes, '/items/42', 'GET')->parameters)->toBe(['id' => 42]);

    $routes->compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);

    expect($routes->generate($routes, 'item', ['id' => 'ABC']))->toBe('/items/ABC')
        ->and($routes->match($routes, '/items/23', 'GET')->parameters)->toBe(['id' => 23]);
})->with(['source', 'cached', 'legacy source matching']);
