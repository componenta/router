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


it('rejects unpreparable referenced payloads before returning or publishing an invalid map', function (bool $cyclic): void {
    $value = 'first';
    $payload = ['value' => &$value];
    if ($cyclic) {
        $payload['self'] =& $payload;
    }

    $source = new Routes();
    // MiddlewareGroup snapshots only its top-level definition slots; nested
    // custom payloads deliberately retain their original value semantics.
    $source->addRoute(RouteRecord::get('home', '/', 'H', middlewares: [
        ['payload' => $payload],
    ]));
    $generator = new RouteCacheGenerator();
    expect($source->match($source, '/', 'GET')->name)->toBe('home');
    expect(fn () => $generator->compile($source))->toThrow(UnsupportedRouteCacheException::class);

    $file = tempnam(sys_get_temp_dir(), 'router-referenced-');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, 'previous artifact');
        expect(fn () => $generator->generate($source, $file))->toThrow(UnsupportedRouteCacheException::class);
        expect(file_get_contents($file))->toBe('previous artifact');
        $value = 'changed';
        expect($source->getRoute('home')->middlewares->toArray()[0]['payload']['value'])->toBe('changed');
    } finally {
        unlink($file);
    }
})->with([false, true]);

it('continues to prepare nested reference-free payloads', function (): void {
    $source = (new Routes())->addRoute(RouteRecord::get('home', '/', 'H', middlewares: [
        ['payload' => ['value' => 'first']],
    ]));
    $map = (new RouteCacheGenerator())->compile($source);
    expect($map['fingerprint'])->toBeString()
        ->and(CompiledRoutes::fromArray($map)->getRoute('home')->toArray())
        ->toBe($source->getRoute('home')->toArray());
});
