<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\Exception\UnsupportedRouteCacheException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;

it('distinguishes unsupported preparation settings from invalid route definitions', function (): void {
    $generator = new RouteCacheGenerator();
    $differentCompiler = new Routes(new Compiler(defaultPatterns: ['id' => '[A-Z]+']));
    expect(fn () => $generator->compile($differentCompiler))->toThrow(UnsupportedRouteCacheException::class);
    $custom = new Routes(syntax: new CompositeSyntax([new CurlySyntax()], new CurlySyntax()));
    expect(fn () => $generator->compile($custom))->toThrow(UnsupportedRouteCacheException::class);
    $supported = new Routes(syntax: new SquareBracketSyntax());
    $supported->addRoute(RouteRecord::get('item', '/items/[id]', 'Handler'));
    expect($generator->compile($supported))->toHaveKey('dynamicIndex');

    $invalid = new Routes();
    $invalid->addRoute(RouteRecord::get('a', '/item', 'A'));
    $invalid->addRoute(RouteRecord::get('b', '/item', 'B'));
    expect(fn () => $generator->compile($invalid))->toThrow(RouteConflictException::class);
});


it('preserves explicit generation syntax when preparing an already prepared collection', function (): void {
    $compiler = new Compiler(new CurlySyntax());
    $source = new Routes($compiler, new SquareBracketSyntax());
    $source->addRoute(RouteRecord::get('item', '/items/[id]', 'Handler'));
    $generator = new RouteCacheGenerator($compiler);
    $prepared = CompiledRoutes::fromArray($generator->compile($source));
    $again = CompiledRoutes::fromArray($generator->compile($prepared));

    foreach ([$source, $prepared, $again] as $collection) {
        expect($collection->generate($collection, 'item', ['id' => 42]))->toBe('/items/42')
            ->and($collection->match($collection, '/items/[id]', 'GET')->name)->toBe('item');
    }

    expect($generator->compile($again))->toBe($generator->compile($source));
});

it('rejects a different compiler when preparing a prepared collection', function (): void {
    $compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'Handler'));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source));

    expect(fn () => (new RouteCacheGenerator())->compile($prepared))
        ->toThrow(UnsupportedRouteCacheException::class);

    $again = CompiledRoutes::fromArray((new RouteCacheGenerator($prepared->compiler))->compile($prepared));
    expect($again->generate($again, 'item', ['id' => 'ABC']))->toBe('/items/ABC')
        ->and($again->match($again, '/items/ABC', 'GET')->parameters)->toBe(['id' => 'ABC']);
});
