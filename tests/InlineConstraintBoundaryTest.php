<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('retains the complete inline expression and does not detect nested syntax markers', function (string $pattern): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', $pattern, 'Handler'));
    $compiled = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $compiled] as $routes) {
        expect($routes->match($routes, '/items/abc', 'GET')->parameters)->toBe(['value' => 'abc'])
            ->and($routes->generate($routes, 'item', ['value' => 'abc']))->toBe('/items/abc')
            ->and(fn () => $routes->match($routes, '/items/xyz', 'GET'))->toThrow(RouteNotFoundException::class);
    }
})->with([
    'curly lookahead' => ['/items/{value:(?=abc)[a-z]+}'],
    'square lookahead with named capture' => ['/items/[value:(?=abc)(?<inner>[a-z]+)]'],
    'angle named capture' => ['/items/<value:(?=abc)(?<inner>[a-z]+)>'],
    'colon nested parentheses' => ['/items/:value((?=abc)(?:[a-z]+))'],
]);

it('rejects unfinished placeholders instead of treating them as literal paths', function (string $pattern): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('broken', $pattern, 'Handler'));
    expect(fn () => $routes->match($routes, '/items/abc', 'GET'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new RouteCacheGenerator())->compile($routes))->toThrow(InvalidArgumentException::class);
})->with(['/items/{value:[a-z]+', '/items/[value:(abc)', '/items/<value:(abc)', '/items/:value((abc)']);
