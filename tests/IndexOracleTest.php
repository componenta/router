<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves every eligible candidate against the compiled expression without using the literal index as the oracle', function (): void {
    // Each collection contains exactly one route. A direct PCRE match is
    // independent of both source and prepared candidate selection.
    $compiler = new Compiler();
    $patterns = [
        '/a/{id}', '/a/[id]', '/a/<id>', '/a/:id',
        '/{id?}', '/[?id]', '/<id?>', '/:id?',
        '/a/{id?}/end', '/a/[?id]/end', '/a/<id?>/end', '/a/:id?/end',
        '/a/{id}/s123/{value}', '/a/[id]/s123/[value]',
        '/a/<id>/s123/<value>', '/a/:id/s123/:value',
        '/{scope}/s123/{value}', '/a/{any}/s123/{value}',
        '/a/{id?}/s123/{value}', '/a/{id}/s123/{value?}',
        '/a/{id}/[value]/end', '/a/{id}/:value/end',
        '/a/{value}x', '/a/x{id?}', '/a//{id?}',
        '/a/{value:a//b}', '/a/{value:foo|bar}/end',
        '/a/{value:[^/]+(*ACCEPT)}/end', '/a/{value:.*}/end',
        '/a/{first}/{second:(?P=first)}',
        '/a//end', '/a/[123]',
    ];
    $uris = [
        '/', '/a', '/a/', '/a//', '/a/1', '/a/abc', '/a/end',
        '/a/1/end', '/a/abc/end', '/a/1/s123/x', '/a/abc/s123/x',
        '/a/1/s123', '/a/s123/x', '/a/1/s123/x/y', '/a/1/s124/x',
        '/b/1/s123/x', '/a/1/2/s123/x', '/a/1/[value]/end',
        '/a/1/:value/end', '/a/1x', '/a/x', '/a/x42', '/a//end',
        '/a/a//b', '/a/foo', '/a/bar', '/a/foo/end', '/a/bar/end',
        '/a/abc/abc', '/a/abc/xyz', '/a/[123]', '/missing',
    ];

    // A deterministic corpus provides additional literal, optional, and
    // positional combinations without introducing malformed route syntax.
    $random = new Random\Randomizer(new Random\Engine\Mt19937(907311));
    $markers = [
        ['{id}', '{value}', '{id?}', '{value?}'],
        ['[id]', '[value]', '[?id]', '[?value]'],
        ['<id>', '<value>', '<id?>', '<value?>'],
        [':id', ':value', ':id?', ':value?'],
    ];
    for ($i = 0; $i < 80; ++$i) {
        $set = $markers[$random->getInt(0, 3)];
        $head = ['', '/a', '/a/b'][$random->getInt(0, 2)];
        $middle = ['/end', '/s123', '/b', ''][$random->getInt(0, 3)];
        $tail = ['', '/end', '/a'][$random->getInt(0, 2)];
        $patterns[] = $head . '/' . $set[$random->getInt(0, 1) * 2] . $middle . '/' . $set[$random->getInt(0, 1) * 2 + 1] . $tail;
    }
    $patterns = array_values(array_unique($patterns));

    foreach ($patterns as $pattern) {
        $record = RouteRecord::get('selected', $pattern, 'Handler');
        $compiled = $compiler->compile($record->path, $record->tokens, $record->defaults);
        $source = new Routes($compiler);
        $source->addRoute($record);
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source));

        foreach ($uris as $uri) {
            $expected = $compiled->hasParameters()
                ? preg_match('#\A(?:' . $compiled->regex . ')\z#', $uri) === 1
                : $uri === $record->path;
            foreach ([$source, $prepared] as $routes) {
                try {
                    $match = $routes->match($routes, $uri, 'GET');
                    expect($expected)->toBeTrue($record->path . ' unexpectedly matched ' . $uri)
                        ->and($match->name)->toBe('selected');
                } catch (RouteNotFoundException) {
                    expect($expected)->toBeFalse($record->path . ' unexpectedly missed ' . $uri);
                }
            }
        }
    }
});
