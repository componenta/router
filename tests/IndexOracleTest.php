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
        '/docs/{version?}.json', '/docs/[?version].json', '/docs/<version?>.json', '/docs/:version?.json',
        '/api/docs/{left?}/{right?}.json', '/api/docs/[?left]/[?right].json',
        '/api/docs/<left?>/<right?>.json', '/api/docs/:left?/:right?.json',
        '/docs/{version=1}.json', '/docs/{version?}/{id}.json',
        '/docs/{version?}/end', '/docs/{version?}/{id?}', '/docs/{version?}{id?}.json',
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
        '/docs.json', '/docs/1.json', '/docs/abc.json', '/docs', '/docs/end', '/docs/1/end',
        '/api/docs.json', '/api/docs/1.json', '/api/docs/1/2.json',
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

it('retains optional routes whose omitted slash joins literal path fragments', function (string $pattern, string $uri): void {
    $source = new Routes();
    $source->addRoute(new RouteRecord('document', $pattern, 'Handler', ['GET', 'POST']));
    $generator = new RouteCacheGenerator();
    $map = $generator->compile($source);
    $file = tempnam(sys_get_temp_dir(), 'route-boundary-');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }
    try {
        $generator->generate($source, $file);
        foreach ([$source, CompiledRoutes::fromArray($map), CompiledRoutes::fromCache($file)] as $routes) {
            expect($routes->generate($routes, 'document'))->toBe($uri)
                ->and($routes->match($routes, $uri, 'GET')->name)->toBe('document')
                ->and($routes->match($routes, $uri, 'POST')->name)->toBe('document');
            try {
                $routes->match($routes, $uri, 'DELETE');
                throw new LogicException('Expected method mismatch.');
            } catch (\Componenta\Http\Router\Exception\MethodNotAllowedException $error) {
                expect($error->allowedMethods)->toBe(['GET', 'POST']);
            }
        }
    } finally {
        unlink($file);
    }
})->with([
    ['/docs/{version?}.json', '/docs.json'],
    ['/docs/[?version].json', '/docs.json'],
    ['/docs/<version?>.json', '/docs.json'],
    ['/docs/:version?.json', '/docs.json'],
    ['/api/docs/{left?}/{right?}.json', '/api/docs.json'],
    ['/api/docs/[?left]/[?right].json', '/api/docs.json'],
    ['/api/docs/<left?>/<right?>.json', '/api/docs.json'],
    ['/api/docs/:left?/:right?.json', '/api/docs.json'],
    ['/api/docs/{left?}{right?}.json', '/api/docs.json'],
]);

it('keeps a complete literal prefix when omitted parameters cannot join segments', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('tail', '/docs/{version?}', 'Handler'));
    $source->addRoute(RouteRecord::get('chain', '/docs/{version?}/{id?}', 'Handler'));
    $source->addRoute(RouteRecord::get('suffix', '/docs/{version?}/end', 'Handler'));
    $map = (new RouteCacheGenerator())->compile($source);
    expect($map['dynamicIndex']['GET']['/docs'])->toBe([0, 1])
        ->and($map['dynamicIndex']['GET']['!end:/docs'])->toBe([2]);
});


it('preserves registration order across many overlapping prefix buckets and methods', function (int $count): void {
    $source = new Routes();
    // All prefixes are eligible, but the first registered candidate does not
    // match. The next registration wins, not the deepest matching prefix.
    $source->addRoute(new RouteRecord('reject', '/a/{any}', 'Reject', ['GET', 'POST'], tokens: ['any' => 'never']));
    $prefix = '/a';
    for ($i = 2; $i <= $count; ++$i) {
        $prefix .= '/a';
        $source->addRoute(new RouteRecord('route.' . $i, $prefix . '/{any}', 'Handler', ['GET', 'POST']));
    }
    $uri = $prefix . '/target';
    $generator = new RouteCacheGenerator();
    $map = $generator->compile($source);
    $file = tempnam(sys_get_temp_dir(), 'router-overlap-');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }
    try {
        $generator->generate($source, $file);
        foreach ([$source, CompiledRoutes::fromArray($map), CompiledRoutes::fromCache($file)] as $routes) {
            foreach (['GET', 'POST'] as $method) {
                $match = $routes->match($routes, $uri, $method);
                expect($match->name)->toBe('route.2')
                    ->and($match->parameters)->toBe(['any' => substr($uri, strlen('/a/a/'))]);
            }
            try {
                $routes->match($routes, $uri, 'DELETE');
                throw new LogicException('Expected method mismatch.');
            } catch (\Componenta\Http\Router\Exception\MethodNotAllowedException $error) {
                expect($error->allowedMethods)->toBe(['GET', 'POST']);
            }
        }
    } finally {
        unlink($file);
    }
})->with([4, 64, 256, 512]);
