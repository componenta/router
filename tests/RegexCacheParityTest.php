<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves parameters named MARK in small and large route collections', function (int $count): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('selected', '/items/{MARK}', 'ItemsHandler'));
    for ($index = 1; $index < $count; ++$index) {
        $routes->addRoute(RouteRecord::get('other-' . $index, '/other-' . $index . '/{id}', 'OtherHandler'));
    }
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($routes));

    foreach ([$routes, $prepared] as $matcher) {
        expect($matcher->match($matcher, '/items/17', 'GET')->name)->toBe('selected')
            ->and($matcher->match($matcher, '/items/17', 'GET')->parameters)->toBe(['MARK' => 17])
            ->and($matcher->generate($matcher, 'selected', ['MARK' => 23]))->toBe('/items/23')
            ->and($matcher->match($matcher, '/other-1/19', 'GET')->parameters)->toBe(['id' => 19]);
        expect(fn () => $matcher->match($matcher, '/items/17', 'POST'))->toThrow(MethodNotAllowedException::class);
    }
})->with(['small collection' => 2, 'large collection' => 5001]);

it('preserves capture references when preparing multiple route expressions', function (): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('before', '/before/[id]', 'BeforeHandler'));
    $routes->addRoute(RouteRecord::get('pair', '/pair/[value]', 'PairHandler', tokens: ['value' => '(a)(b)\2']));
    $routes->addRoute(RouteRecord::get('triple', '/triple/[value]', 'TripleHandler', tokens: ['value' => '(a)(b)(c)\3']));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($routes));

    foreach ([$routes, $prepared] as $matcher) {
        expect($matcher->match($matcher, '/before/12', 'GET')->parameters)->toBe(['id' => 12])
            ->and($matcher->match($matcher, '/pair/aba', 'GET')->parameters)->toBe(['value' => 'aba'])
            ->and($matcher->match($matcher, '/triple/abcb', 'GET')->parameters)->toBe(['value' => 'abcb']);
        expect(fn () => $matcher->match($matcher, '/pair/abb', 'GET'))->toThrow(RouteNotFoundException::class);
        expect(fn () => $matcher->match($matcher, '/pair/aba', 'POST'))->toThrow(MethodNotAllowedException::class);
    }
});
