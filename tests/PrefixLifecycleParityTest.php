<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;

function routeLifecycleObservation($routes, string $uri, string $method): array
{
    try {
        $match = $routes->match($routes, $uri, $method);
        return [$match->name, $match->parameters, $match->route->toArray(), $match->middlewares?->toArray()];
    } catch (MethodNotAllowedException $error) {
        return [$error::class, $error->getCode(), $error->allowedMethods];
    } catch (RouteNotFoundException $error) {
        return [$error::class, $error->getCode()];
    }
}

it('preserves registration order and complete observations across overlapping prefixes, wildcard routes and methods', function (int $count): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('earlier.wildcard', '/{scope}/items/{id}', 'Wildcard', ['Auth']));
    $source->addRoute(RouteRecord::get('later.literal', '/api/items/{id}', 'Literal'));
    $source->addRoute(RouteRecord::get('root', '/{value?}', 'Root'));
    $source->addRoute(RouteRecord::post('post', '/api/items/{id}', 'Post'));
    $source->addRoute(RouteRecord::get('static', '/api/items/all', 'All', defaults: ['scope' => 'read']));
    $source->group('private', '/private', middleware: ['Auth'])->group('nested', '/nested', middleware: ['Policy'])
        ->addRoute(RouteRecord::get('show', '/{id}/{page?}', 'Show', defaults: ['page' => 1]));
    for ($i = 0; $i < $count; ++$i) {
        $source->addRoute(RouteRecord::get('item.' . $i, '/catalog/' . $i . '/{id}', 'Item'));
    }
    $source->addRoute(RouteRecord::get('complex', '/pair/{value}', 'Pair', tokens: ['value' => '(a)(b)\2']));
    $source->addRoute(RouteRecord::get('after.complex', '/after/{id}', 'After'));
    $source->addRoute(RouteRecord::get('after.second', '/after/second/{id}', 'AfterSecond'));

    $file = tempnam(sys_get_temp_dir(), 'router_parity_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        (new RouteCacheGenerator())->generate($source, $file);
        $prepared = CompiledRoutes::fromCache($file);
        $cases = [
            ['/api/items/42', 'GET'], ['/api/items/42', 'POST'], ['/api/items/42', 'DELETE'],
            ['/api/items/all', 'GET'], ['/', 'GET'], ['/private/nested/17', 'GET'],
            ['/private/nested/17/2', 'GET'], ['/absent/no', 'GET'], ['/pair/aba', 'GET'],
            ['/pair/abb', 'GET'], ['/catalog/0/3', 'GET'], ['/catalog/' . ($count - 1) . '/3', 'GET'],
            ['/after/4', 'GET'], ['/after/second/5', 'GET'],
        ];
        foreach (range(1, 2) as $_) {
            foreach ($cases as [$uri, $method]) {
                expect(routeLifecycleObservation($prepared, $uri, $method))
                    ->toBe(routeLifecycleObservation($source, $uri, $method));
            }
            expect($prepared->generate($prepared, 'private.nested.show', ['id' => 17]))
                ->toBe($source->generate($source, 'private.nested.show', ['id' => 17]));
        }
        expect($prepared->match($prepared, '/api/items/42', 'GET')->name)->toBe('earlier.wildcard');
        expect($prepared->match($prepared, '/private/nested/17', 'GET')->middlewares?->toArray())->toBe(['Auth', 'Policy']);
        $data = require $file;
        expect(max(array_map(static fn (array $chunk): int => count($chunk['routeMap']), $data['dynamicChunks']['GET'])))->toBeLessThanOrEqual(24);
        expect(array_any($data['dynamicChunks']['GET'], static fn (array $chunk): bool => count($chunk['routeMap']) > 1))->toBeTrue();
    } finally { unlink($file); }
})->with([100, 1000, 5001]);

it('prepares the selected compiler syntax without reparsing literal text using another syntax', function (): void {
    $compiler = new Compiler(new SquareBracketSyntax());
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('literal', '/literal/{unfinished', 'Handler'));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source));
    expect($prepared->generate($prepared, 'literal'))->toBe('/literal/{unfinished')
        ->and(routeLifecycleObservation($prepared, '/literal/{unfinished', 'GET'))
        ->toBe(routeLifecycleObservation($source, '/literal/{unfinished', 'GET'));
});

it('rejects a changed but well-formed prepared expression and reference-backed data', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'Handler'));
    $map = (new RouteCacheGenerator())->compile($source);
    $altered = $map;
    $altered['dynamicChunks']['GET'][0]['regex'] = '#\A/items/(?P<id>[a-z]+)\z#';
    expect(CompiledRoutes::tryFromArray($altered))->toBeNull();
    $handler = 'Handler';
    $map['routeData']['item']['handler'] =& $handler;
    expect(CompiledRoutes::tryFromArray($map))->toBeNull();
});

it('detaches caller-owned reference slots in route and compiler definitions', function (): void {
    $pattern = '\d+';
    $defaults = 1;
    $method = 'action';
    $compiler = new Compiler(defaultPatterns: ['id' => &$pattern]);
    $record = RouteRecord::get('item', '/items/{id}', ['Controller', &$method], defaults: ['page' => &$defaults]);
    $source = new Routes($compiler);
    $source->addRoute($record);
    $pattern = '[a-z]+';
    $defaults = 2;
    $method = 'changed';
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source));
    foreach ([$source, $prepared] as $routes) {
        $match = $routes->match($routes, '/items/42', 'GET');
        expect($match->parameters)->toBe(['page' => 1, 'id' => 42])
            ->and($match->handler->value)->toBe(['Controller', 'action']);
    }
});


it('narrows a shared prefix by its guaranteed final literal segment without changing order or custom token semantics', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('wildcard.first', '/{scope}/{id}/first', 'Wildcard'));
    for ($i = 0; $i < 5001; ++$i) {
        $source->addRoute(new RouteRecord('item.' . $i, '/items/{id}/' . $i, 'Item', ['GET', 'POST']));
    }
    $source->addRoute(RouteRecord::get('first.literal', '/items/{id}/first', 'Literal'));
    $source->addRoute(RouteRecord::get('optional', '/archive/{id?}/end', 'Archive'));
    $source->addRoute(RouteRecord::get('custom', '/custom/{id}/end', 'Custom', tokens: ['id' => '\d+(*ACCEPT)']));
    $map = (new RouteCacheGenerator())->compile($source);
    $prepared = CompiledRoutes::fromArray($map);
    foreach ([
        ['/items/42/0', 'GET'], ['/items/42/5000', 'GET'], ['/items/42/5000', 'POST'],
        ['/items/42/5000', 'DELETE'], ['/items/42/first', 'GET'],
        ['/archive/end', 'GET'], ['/archive/42/end', 'GET'], ['/archive/42/other', 'GET'],
        ['/custom/42', 'GET'], ['/custom/42/end', 'GET'], ['/custom/no', 'GET'],
    ] as [$uri, $method]) {
        expect(routeLifecycleObservation($prepared, $uri, $method))->toBe(routeLifecycleObservation($source, $uri, $method));
    }
    expect($prepared->match($prepared, '/items/42/first', 'GET')->name)->toBe('wildcard.first');
    expect($prepared->match($prepared, '/custom/42', 'GET')->name)->toBe('custom');
    expect($map['prefixIndex']['GET']['!5000:/items'])->toHaveCount(1);
});
