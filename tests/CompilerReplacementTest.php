<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('keeps compiler configuration immutable across cold and warm matching', function (bool $cached): void {
    $compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemHandler'));
    $routes = $cached ? CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source)) : $source;
    $replace = static function () use ($routes): void {
        $routes->compiler = new Compiler(defaultPatterns: ['id' => '[a-z]+']);
    };

    expect($replace)->toThrow(Error::class);
    for ($i = 0; $i < 2; ++$i) {
        expect($routes->generate($routes, 'item', ['id' => 'ABC']))->toBe('/items/ABC')
            ->and($routes->match($routes, '/items/ABC', 'GET')->parameters)->toBe(['id' => 'ABC'])
            ->and(fn () => $routes->generate($routes, 'item', ['id' => 'abc']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $routes->match($routes, '/items/abc', 'GET'))->toThrow(RouteNotFoundException::class)
            ->and($replace)->toThrow(Error::class);
    }
})->with(['source' => false, 'prepared' => true]);


it('keeps custom composite parser selection stable before and after building the source index', function (bool $warm): void {
    $original = new \Componenta\Http\Router\Syntax\CurlySyntax();
    $selected = $original;
    $fallback = new \Componenta\Http\Router\Syntax\SquareBracketSyntax();
    $syntax = new \Componenta\Http\Router\Syntax\CompositeSyntax([&$selected], $fallback);
    $copy = clone $syntax;
    $compiler = new Compiler($syntax);
    $routes = (new Routes($compiler))->addRoute(RouteRecord::get('item', '/items/{id}', 'Handler'));

    if ($warm) {
        expect($routes->match($routes, '/items/42', 'GET')->parameters)->toBe(['id' => 42]);
    }

    $selected = new \Componenta\Http\Router\Syntax\ColonSyntax();
    foreach ([$syntax, $copy] as $parser) {
        expect($parser->detect('/items/{id}'))->toBe($original)
            ->and($parser->detect('/items/[id]'))->toBe($fallback);
    }
    expect($routes->generate($routes, 'item', ['id' => 42]))->toBe('/items/42')
        ->and($routes->match($routes, '/items/42', 'GET')->parameters)->toBe(['id' => 42]);

    // Even an invalid new value in a caller slot must not damage the configured parser.
    $selected = null;
    expect($syntax->canParse('/items/{id}'))->toBeTrue()
        ->and($copy->hasParameter('/items/{id}'))->toBeTrue()
        ->and($routes->generate($routes, 'item', ['id' => 43]))->toBe('/items/43')
        ->and($routes->match($routes, '/items/43', 'GET')->parameters)->toBe(['id' => 43])
        ->and(fn () => $routes->match($routes, '/items/abc', 'GET'))->toThrow(RouteNotFoundException::class);

    // The custom parser list still cannot be represented by the standard prepared format.
    expect(fn () => (new RouteCacheGenerator($compiler))->compile($routes))
        ->toThrow(\Componenta\Http\Router\Exception\UnsupportedRouteCacheException::class);
})->with(['cold' => false, 'warm' => true]);

it('preserves custom composite parser order and identities without retaining caller aliases', function (): void {
    $first = new \Componenta\Http\Router\Syntax\CurlySyntax();
    $second = new \Componenta\Http\Router\Syntax\CurlySyntax();
    $fallback = new \Componenta\Http\Router\Syntax\SquareBracketSyntax();
    $slot = $first;
    $parsers = [&$slot, $second, &$slot];
    $syntax = new \Componenta\Http\Router\Syntax\CompositeSyntax($parsers, $fallback);

    $slot = $fallback;
    $parsers[1] = new \Componenta\Http\Router\Syntax\ColonSyntax();
    expect($syntax->detect('/{id}'))->toBe($first)
        ->and($syntax->detect('/[id]'))->toBe($fallback)
        ->and((new \Componenta\Http\Router\Syntax\CompositeSyntax([], $fallback))->detect('/{id}'))->toBe($fallback);
});
