<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Compiler;
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
    expect($generator->compile($supported))->toHaveKey('dynamicChunks');

    $invalid = new Routes();
    $invalid->addRoute(RouteRecord::get('a', '/item', 'A'));
    $invalid->addRoute(RouteRecord::get('b', '/item', 'B'));
    expect(fn () => $generator->compile($invalid))->toThrow(RouteConflictException::class);
});
