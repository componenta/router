<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

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
