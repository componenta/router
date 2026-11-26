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
        $source->addRoute(RouteRecord::get('custom', $prefix . '-custom/{value}', 'Custom', tokens: ['value' => 'a+a+a+a+a+b']));
        $source->addRoute(RouteRecord::get('later', $prefix . '-custom/{other}', 'Later'));
        $map = (new RouteCacheGenerator())->compile($source);
        $prepared = CompiledRoutes::fromArray($map);
        $uris = [$prefix, $prefix . '/a', $prefix . '/abc/42', $prefix . '/a/b/end',
            $prefix . '-custom/aaaaab', $prefix . '-custom/' . str_repeat('a', 100) . 'c', '/missing'];
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

it('stores individual expressions once and rejects missing or malformed expressions', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('first', '/first/{id}', 'First'));
    $source->addRoute(RouteRecord::get('second', '/second/{id}', 'Second'));
    $map = (new RouteCacheGenerator())->compile($source);
    expect($map['routeData']['first']['regex'])->toBe('#\A(?:/first/(?P<id>\d+))\z#')
        ->and($map['dynamicIndex']['GET'])->toBe(['/first' => [0], '/second' => [1]])
        ->and(CompiledRoutes::tryFromArray($map))->not->toBeNull();
    foreach ([null, 'invalid(', '#\A(?:/second/(?P<id>\d+))\z#'] as $expression) {
        $changed = $map;
        $changed['routeData']['first']['regex'] = $expression;
        expect(CompiledRoutes::tryFromArray(PreparedMap::seal($changed)))->toBeNull();
    }
});

it('preserves the outcome of each individual expression even when combined PCRE could succeed', function (string $jit): void {
    $oldJit = ini_get('pcre.jit');
    $oldLimit = ini_get('pcre.backtrack_limit');
    $observe = static function ($routes, string $uri, string $method): array {
        try {
            $match = $routes->match($routes, $uri, $method);
            return [$match->name, $match->parameters, $match->route->toArray()];
        } catch (RouterException $error) {
            return [$error::class, $error->getCode(), $error->getMessage()];
        }
    };
    try {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', '1000000');
        $source = new Routes();
        foreach (['/{any}/{slug}', '/a/{id}', '/a/{value?}/end', '/{id}/end', '/{any}/{id}', '/a/{any}/{value?}', '/{any}/{value?}/{id?}'] as $i => $path) {
            $source->addRoute(new RouteRecord('r' . $i, $path, 'Handler', $i === 0 ? ['POST'] : ['GET', 'POST']));
        }
        $cached = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
        $observe($source, '/a/1', 'GET');
        foreach ([1,2,3,4,5,7,10,20,50,100,1000000] as $limit) {
            ini_set('pcre.backtrack_limit', (string) $limit);
            foreach (['/a/1', '/a/b/end', '/a/b', '/a/b/c', '/a/1/2', '/a/b/c/3', '/a/' . str_repeat('a/', 30) . 'end'] as $uri) {
                foreach (['GET', 'POST', 'DELETE'] as $method) {
                    expect($observe($cached, $uri, $method))->toBe($observe($source, $uri, $method));
                }
            }
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $oldLimit);
        ini_set('pcre.jit', (string) $oldJit);
    }
})->with(['0','1']);

it('does not interpret a parameterized pattern as static under restrictive PCRE limits', function (string $pattern): void {
    $oldJit = ini_get('pcre.jit');
    $oldLimit = ini_get('pcre.backtrack_limit');
    try {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        $compiler = new \Componenta\Http\Router\Compiler();
        $compiled = $compiler->compile('/lexer-' . bin2hex(random_bytes(4)) . '/' . $pattern);
        expect($compiled->parameterNames())->toBe(['id']);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $oldLimit);
        ini_set('pcre.jit', (string) $oldJit);
    }
})->with(['{id}', '[id]', '<id>', ':id']);

it('rejects control bytes in generated values regardless of PCRE limits', function (): void {
    $previous = ini_get('pcre.backtrack_limit');
    try {
        ini_set('pcre.backtrack_limit', '1');
        foreach (array_merge(range(0, 31), [127]) as $byte) {
            expect(fn () => \Componenta\Http\Router\Internal\PathParameter::encode('a' . chr($byte) . 'b', 'value'))
                ->toThrow(InvalidArgumentException::class);
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $previous);
    }
});
