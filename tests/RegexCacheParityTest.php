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


it('loads contextual inline capture references in every supported syntax', function (string $pattern): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('pair', $pattern, 'PairHandler'));
    $file = tempnam(sys_get_temp_dir(), 'route_context_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        (new RouteCacheGenerator())->generate($source, $file);
        $cached = CompiledRoutes::fromCache($file);
        foreach ([$source, $cached] as $routes) {
            expect($routes->match($routes, '/abc/abc', 'GET')->parameters)->toBe(['first' => 'abc', 'second' => 'abc'])
                ->and($routes->generate($routes, 'pair', ['first' => 'abc', 'second' => 'abc']))->toBe('/abc/abc');
            expect(fn () => $routes->match($routes, '/abc/xyz', 'GET'))->toThrow(RouteNotFoundException::class);
            expect(fn () => $routes->match($routes, '/abc/abc', 'POST'))->toThrow(MethodNotAllowedException::class);
        }
    } finally {
        restore_error_handler();
        unlink($file);
    }
})->with([
    'curly named' => ['/{first}/{second:(?P=first)}'],
    'curly optional' => ['/{first}/{second:(?P=first)?}'],
    'curly numbered' => ['/{first}/{second:\1}'],
    'square' => ['/[first]/[second:(?P=first)]'],
    'angle' => ['/<first>/<second:(?P=first)>'],
    'colon' => ['/:first/:second((?P=first))'],
]);

it('accepts effective explicit overrides of contextual optional constraints', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{value:(?P=missing)?}', 'Handler', tokens: ['value' => '[a-z]+']));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $prepared] as $routes) {
        expect($routes->match($routes, '/items/abc', 'GET')->parameters)->toBe(['value' => 'abc'])
            ->and($routes->match($routes, '/items', 'GET')->parameters)->toBe([])
            ->and($routes->generate($routes, 'item', ['value' => 'abc']))->toBe('/items/abc');
    }
});


it('never returns PCRE control metadata instead of a captured MARK parameter', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('plain', '/plain/{MARK}', 'Handler'));
    $source->addRoute(RouteRecord::get('collision', '/collision/{MARK}', 'Handler', tokens: ['MARK' => '[a-z]+(*MARK:label)']));
    $cached = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $cached] as $routes) {
        expect($routes->match($routes, '/plain/abc', 'GET')->parameters)->toBe(['MARK' => 'abc'])
            ->and($routes->generate($routes, 'plain', ['MARK' => 'abc']))->toBe('/plain/abc');
        expect(fn () => $routes->match($routes, '/collision/abc', 'GET'))
            ->toThrow(\Componenta\Http\Router\Exception\RoutePatternException::class, 'PCRE MARK metadata conflicts');
        expect(fn () => $routes->generate($routes, 'collision', ['MARK' => 'abc']))
            ->toThrow(\Componenta\Http\Router\Exception\RoutePatternException::class, 'PCRE MARK metadata conflicts');
    }
});
