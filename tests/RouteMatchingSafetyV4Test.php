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


it('converts only lossless ASCII decimal parameter representations', function (string $wire, string|int|float $expected): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', '/items/{value}', 'Handler'));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $prepared] as $routes) {
        expect($routes->match($routes, '/items/' . $wire, 'GET')->parameters)->toBe(['value' => $expected]);
    }
})->with([
    'zero' => ['0', 0],
    'integer' => ['42', 42],
    'negative integer' => ['-42', -42],
    'positive integer limit' => [(string) PHP_INT_MAX, PHP_INT_MAX],
    'negative integer limit' => [(string) PHP_INT_MIN, PHP_INT_MIN],
    'leading zero' => ['00042', '00042'],
    'negative zero' => ['-0', '-0'],
    'explicit plus' => ['+42', '+42'],
    'integer overflow' => [(string) PHP_INT_MAX . '0', (string) PHP_INT_MAX . '0'],
    'fraction' => ['1.25', 1.25],
    'negative fraction' => ['-1.25', -1.25],
    'trailing zero' => ['1.0', '1.0'],
    'leading zero fraction' => ['01.25', '01.25'],
    'missing integral part' => ['.5', '.5'],
    'missing fractional part' => ['1.', '1.'],
    'precision loss' => ['1.000000000000000000001', '1.000000000000000000001'],
    'exponential notation' => ['1e3', '1e3'],
    'Arabic-Indic digits' => ['١٢٣', '١٢٣'],
    'fullwidth digits' => ['１２３', '１２３'],
    'wire-encoded digits' => ['%31%32', '%31%32'],
    'alphanumeric' => ['42x', '42x'],
]);
