<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('rejects a prepared map missing a route index or assigning a route to another method', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('home', '/', 'Controller'));
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'Controller'));
    $map = (new RouteCacheGenerator())->compile($source);
    expect(CompiledRoutes::tryFromArray($map))->not->toBeNull();
    $missing = $map;
    unset($missing['staticRoutes']);
    expect(CompiledRoutes::tryFromArray($missing))->toBeNull();
    $misdirected = $map;
    $misdirected['staticRoutes'] = ['POST' => ['/' => 'home']];
    expect(CompiledRoutes::tryFromArray($misdirected))->toBeNull();
    $missingDynamic = $map;
    unset($missingDynamic['regex'], $missingDynamic['routeMap']);
    expect(CompiledRoutes::tryFromArray($missingDynamic))->toBeNull();
});

it('refuses to prepare an index using a different compiler than its source', function (): void {
    $routes = new Routes(new Compiler(defaultPatterns: ['id' => '[A-Z]+']));
    $routes->addRoute(RouteRecord::get('item', '/items/{id}', 'Controller'));
    expect(fn () => (new RouteCacheGenerator())->compile($routes))->toThrow(RuntimeException::class);
});
