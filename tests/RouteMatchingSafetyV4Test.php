<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves numeric identifiers when coercion would lose information', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{value}', 'Controller'));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        foreach (['000123', (string) PHP_INT_MAX . '0', '1.000000000000000000001'] as $identifier) {
            expect($routes->match($routes, '/items/' . $identifier, 'GET')->parameters['value'])->toBe($identifier);
        }
        expect($routes->match($routes, '/items/123', 'GET')->parameters['value'])->toBe(123);
    }
});

it('rejects duplicate static method-path registrations consistently', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('first', '/items', 'Controller'));
    $source->addRoute(RouteRecord::get('second', '/items', 'OtherController'));
    expect(fn () => $source->match($source, '/items', 'GET'))->toThrow(RouteConflictException::class);
    expect(fn () => (new RouteCacheGenerator())->compile($source))->toThrow(RouteConflictException::class);
});

it('uses absolute PCRE anchors and validates the implicit token on URL generation', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('numeric', '/items/{id}', 'Controller'));
    $source->addRoute(RouteRecord::get('plain', '/plain/{value}', 'Controller'));
    $source->addRoute(RouteRecord::get('alternative', '/choice/{value}', 'Controller', tokens: ['value' => 'abc|123']));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        expect(fn () => $routes->match($routes, "/items/123\n", 'GET'))->toThrow(RouteNotFoundException::class);
        expect(fn () => $routes->generate($routes, 'plain', ['value' => 'a/b']))->toThrow(InvalidArgumentException::class);
        expect(fn () => $routes->generate($routes, 'alternative', ['value' => 'abcXYZ']))->toThrow(InvalidArgumentException::class);
    }
});
