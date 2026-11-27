<?php

declare(strict_types=1);

use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\AngleBracketSyntax;
use Componenta\Http\Router\Syntax\ColonSyntax;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;
use Componenta\Http\Router\Syntax\SyntaxConverter;

it('preserves normalized path layout and parameter names during colon conversion', function (string $path, string $expected): void {
    $source = (new CompositeSyntax())->detect($path);
    $target = new ColonSyntax();
    $converted = (new SyntaxConverter())->toColonSyntax($path);
    expect($converted)->toBe($expected)
        ->and($target->parse($converted))->toBe($source->parse($source->normalize($path)))
        ->and($target->toRegex($converted, []))->toBe($source->toRegex($source->normalize($path), []));
})->with([
    ['/a//{id}', '/a//:id'],
    ['/a//{id?}', '/a//:id?'],
    ['/a//[?id]', '/a//:id?'],
    ['/a//<id?>', '/a//:id?'],
    ['/a//:id?', '/a//:id?'],
    ['/a/{id}/{name?}', '/a/:id/:name?'],
    ['/a/[id]/[?name]', '/a/:id/:name?'],
    ['/a/<id>/<name?>', '/a/:id/:name?'],
    ['/a/{id:\d+=42}', '/a/:id?'],
    ['/a/:id?=42', '/a/:id?'],
    ['/a/{id}.jpg', '/a/:id.jpg'],
    ['/a/{id?}_thumb', '/a/:id?_thumb'],
    ['/a/[id]/{id}', '/a/[id]/:id'],
    ['/a/[[id]]/[id]', '/a/[[id]]/:id'],
    ['/literal//file', '/literal//file'],
]);

it('rejects normalized layouts that would silently change a parameter or literal segment', function (string $path): void {
    expect(fn () => (new SyntaxConverter())->toColonSyntax($path))->toThrow(InvalidArgumentException::class);
})->with([
    '/a/x{id}', '/a/x{id?}', '/a/{id}_thumb.jpg', '/a/{id}abc',
    '/a/{id}(text)', '/a/{id}=text', '/a/{id}?', '/a/{id}/:other',
]);

it('does not reinterpret literal markers belonging to a different syntax', function (): void {
    expect((new SyntaxConverter(new CurlySyntax()))->toColonSyntax('/a/[value]/{value}'))->toBe('/a/[value]/:value')
        ->and((new SyntaxConverter(new SquareBracketSyntax()))->toColonSyntax('/a/{value}/[value]'))->toBe('/a/{value}/:value')
        ->and((new SyntaxConverter(new AngleBracketSyntax()))->toColonSyntax('/a/[value]/<value>'))->toBe('/a/[value]/:value');
    expect(fn () => (new SyntaxConverter(new CurlySyntax()))->toColonSyntax('/a/:literal'))
        ->toThrow(InvalidArgumentException::class);
});

it('retains literal empty segments and optional separator behavior in actual routing', function (): void {
    $sourceSyntax = new CurlySyntax();
    $targetSyntax = new ColonSyntax();
    $sourcePath = '/a//{value?}';
    $targetPath = (new SyntaxConverter($sourceSyntax))->toColonSyntax($sourcePath);
    $source = (new Routes(new Compiler($sourceSyntax)))->addRoute(RouteRecord::get('item', $sourcePath, 'Handler'));
    $target = (new Routes(new Compiler($targetSyntax)))->addRoute(RouteRecord::get('item', $targetPath, 'Handler'));
    foreach ([$source, $target] as $routes) {
        expect($routes->match($routes, '/a//abc', 'GET')->parameters)->toBe(['value' => 'abc'])
            ->and($routes->match($routes, '/a/', 'GET')->parameters)->toBe([])
            ->and($routes->generate($routes, 'item'))->toBe('/a/')
            ->and($routes->generate($routes, 'item', ['value' => 'abc']))->toBe('/a//abc');
        expect(fn () => $routes->match($routes, '/a/abc', 'GET'))->toThrow(RouteNotFoundException::class);
    }
});
