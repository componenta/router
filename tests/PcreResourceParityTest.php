<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouterException;
use Componenta\Http\Router\HttpMethod;
use Componenta\Http\Router\Internal\PreparedMap;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves route matching observations after PCRE resource limits change', function (string $jit): void {
    $oldJit = ini_get('pcre.jit');
    $oldLimit = ini_get('pcre.backtrack_limit');
    $observe = static function ($routes, string $uri, string $method): array {
        try {
            $result = $routes->match($routes, $uri, $method);
            return [$result->name, $result->parameters, $result->route->toArray()];
        } catch (RouterException $error) {
            return [$error::class, $error->getCode(), $error->getMessage()];
        }
    };
    try {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', '1000000');
        $prefix = '/pcre-resource-' . $jit . '-' . bin2hex(random_bytes(4));
        $source = new Routes();
        foreach ([
            '/{value?}/{other?}',
            '/{value}/{id}',
            '/{any}/end',
            '/{any}',
        ] as $index => $path) {
            $source->addRoute(new RouteRecord('r' . $index, $prefix . $path, 'Handler', ['GET', 'POST']));
        }
        $source->addRoute(RouteRecord::get('custom', $prefix . '/custom/{value}', 'Custom', tokens: ['value' => 'a+a+a+a+a+b']));
        $source->addRoute(RouteRecord::get('later', $prefix . '/custom/{other}', 'Later'));
        $map = (new RouteCacheGenerator())->compile($source);
        $prepared = CompiledRoutes::fromArray($map);
        $uris = [$prefix, $prefix . '/a', $prefix . '/abc/42', $prefix . '/a/b/end',
            $prefix . '/custom/aaaaab', $prefix . '/custom/' . str_repeat('a', 100) . 'c', '/missing'];
        // Build the source index before changing runtime PCRE limits.
        foreach ($uris as $uri) {
            $observe($source, $uri, 'GET');
        }
        foreach ([1, 2, 3, 5, 10, 20, 50, 100, 1000000] as $limit) {
            ini_set('pcre.backtrack_limit', (string) $limit);
            expect(HttpMethod::normalize('GET'))->toBe('GET');
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                foreach ($uris as $uri) {
                    expect($observe($prepared, $uri, $method))->toBe($observe($source, $uri, $method));
                }
            }
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $oldLimit);
        ini_set('pcre.jit', (string) $oldJit);
    }
})->with(['0', '1']);

it('stores individual expressions once and rejects missing or malformed fallback branches', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('first', '/first/{id}', 'First'));
    $source->addRoute(RouteRecord::get('second', '/second/{id}', 'Second'));
    $map = (new RouteCacheGenerator())->compile($source);
    $chunk = $map['dynamicChunks']['GET'][0];
    expect($chunk)->not->toHaveKey('regex')
        ->and($chunk['individual'])->toHaveCount(2)
        ->and(CompiledRoutes::tryFromArray($map))->not->toBeNull();
    foreach ([[], ['invalid('], ['invalid(', 'also invalid(']] as $branches) {
        $changed = $map;
        $changed['dynamicChunks']['GET'][0]['individual'] = $branches;
        expect(CompiledRoutes::tryFromArray(PreparedMap::seal($changed)))->toBeNull();
    }
});
