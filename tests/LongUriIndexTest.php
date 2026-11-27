<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('bounds literal prefix selection without losing wildcard, suffix or positional candidates', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('one', '/a/{id}', 'H'));
    $source->addRoute(RouteRecord::get('optional', '/a//{id?}', 'H'));
    $source->addRoute(RouteRecord::get('suffix', '/a/{any}/end', 'H'));
    $source->addRoute(RouteRecord::get('positional', '/a/{id}/inside/{value}', 'H'));
    $source->addRoute(RouteRecord::post('wild', '/{any}', 'H'));
    $source->addRoute(new RouteRecord('deep', '/a/b/c/d/e/f/g/{id}', 'H', ['GET', 'POST', '0']));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));

    foreach ([$source, $prepared] as $routes) {
        foreach ([
            ['/a/42', 'GET', 'one', ['id' => 42]],
            ['/a/', 'GET', 'optional', []],
            ['/a//42', 'GET', 'optional', ['id' => 42]],
            ['/a/hello/world/end', 'GET', 'suffix', ['any' => 'hello/world']],
            ['/a/42/inside/value', 'GET', 'positional', ['id' => 42, 'value' => 'value']],
            ['/a/b/c/d/e/f/g/42', '0', 'deep', ['id' => 42]],
        ] as [$uri, $method, $name, $parameters]) {
            $result = $routes->match($routes, $uri, $method);
            expect($result->name)->toBe($name)->and($result->parameters)->toBe($parameters);
        }

        try {
            $routes->match($routes, '/a/b/c/d/e/f/g/42', 'DELETE');
            test()->fail('Expected 405');
        } catch (MethodNotAllowedException $error) {
            expect($error->allowedMethods)->toBe(['0', 'GET', 'POST']);
        }

        // The selected maximum prefix ends inside a longer request segment.
        expect(fn () => $routes->match($routes, '/a/b/c/d/e/f/g1234567/42', 'GET'))
            ->toThrow(MethodNotAllowedException::class);
    }
});

it('returns the expected miss for very deep requests even with an empty dynamic index', function (): void {
    $uri = '/absent/' . str_repeat('a/', 20000) . 'end';
    foreach ([new Routes(), (new Routes())->addRoute(RouteRecord::get('item', '/items/{id}', 'H'))] as $source) {
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
        foreach ([$source, $prepared] as $routes) {
            expect(fn () => $routes->match($routes, $uri, 'GET'))->toThrow(RouteNotFoundException::class);
        }
    }
});
