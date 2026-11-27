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


it('preserves lazy prepared records and map validation when PCRE execution limits change', function (string $setting): void {
    $oldJit = ini_get('pcre.jit');
    $oldBacktrack = ini_get('pcre.backtrack_limit');
    $oldRecursion = ini_get('pcre.recursion_limit');
    $observe = static function (callable $operation): array {
        try {
            return ['result', $operation()];
        } catch (RouterException $error) {
            return [$error::class, $error->getMessage()];
        }
    };

    try {
        ini_set('pcre.jit', '0');
        foreach ([1, 2, 3, 5, 10, 20, 100] as $limit) {
            ini_set('pcre.backtrack_limit', '1000000');
            ini_set('pcre.recursion_limit', '100000');
            $source = new Routes();
            $token = '(?:a?){20}';
            $source->addRoute(RouteRecord::get('home', '/home', 'H', tokens: ['unused' => $token]));
            $source->addRoute(RouteRecord::get('dynamic', '/dynamic/{id}', 'H', tokens: ['unused' => $token]));
            $source->addRoute(RouteRecord::get('optional', '/{value?}', 'H'));
            $source->addRoute(RouteRecord::get('other', '/other', 'H'));
            $map = (new RouteCacheGenerator())->compile($source);
            $prepared = CompiledRoutes::fromArray($map);
            $source->match($source, '/other', 'GET');

            // The home and dynamic records in the prepared collection have
            // not yet been materialized.
            ini_set('pcre.' . $setting, (string) $limit);

            foreach ([
                static fn ($routes) => $routes->getRoute('home')->toArray(),
                static fn ($routes) => $routes->match($routes, '/home', 'GET')->route->toArray(),
                static fn ($routes) => $routes->match($routes, '/dynamic/42', 'GET')->route->toArray(),
                static fn ($routes) => $routes->match($routes, '/', 'GET')->parameters,
            ] as $operation) {
                expect($observe(fn () => $operation($prepared)))
                    ->toBe($observe(fn () => $operation($source)));
            }
            expect(CompiledRoutes::tryFromArray($map))->not->toBeNull();

            // Invalid syntax must still fail and must not reach an application
            // warning-to-exception handler.
            set_error_handler(static fn () => throw new LogicException('Unexpected PHP warning.'));
            try {
                expect(fn () => RouteRecord::get('invalid', '/invalid', 'H', tokens: ['bad' => '[']))
                    ->toThrow(InvalidArgumentException::class);
            } finally {
                restore_error_handler();
            }

            // Actual execution limits remain observable routing errors.
            if ($limit === 1) {
                expect(fn () => \Componenta\Http\Router\Internal\RouteRegex::match(
                    \Componenta\Http\Router\Internal\RouteRegex::anchored($token),
                    '',
                ))->toThrow(\Componenta\Http\Router\Exception\RoutePatternException::class);
            }
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $oldBacktrack);
        ini_set('pcre.recursion_limit', (string) $oldRecursion);
        ini_set('pcre.jit', (string) $oldJit);
    }
})->with(['backtrack_limit', 'recursion_limit']);


it('loads contextual optional expressions without matching an empty subject during validation', function (string $pattern): void {
    $oldJit = ini_get('pcre.jit');
    $oldBacktrack = ini_get('pcre.backtrack_limit');
    $oldRecursion = ini_get('pcre.recursion_limit');
    $file = tempnam(sys_get_temp_dir(), 'contextual-route-');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }

    $observe = static function ($routes, string $uri, string $method): array {
        try {
            $match = $routes->match($routes, $uri, $method);
            return [$match->name, $match->parameters, $match->route->toArray()];
        } catch (RouterException $error) {
            return [$error::class, $error->getMessage()];
        }
    };

    try {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1000000');
        ini_set('pcre.recursion_limit', '100000');
        $source = new Routes();
        $source->addRoute(RouteRecord::get('context', $pattern, 'H'));
        $source->addRoute(RouteRecord::get('static', '/static', 'H'));
        $generator = new RouteCacheGenerator();
        $map = $generator->compile($source);
        $generator->generate($source, $file);
        $source->match($source, '/static', 'GET');

        foreach (['backtrack_limit', 'recursion_limit'] as $setting) {
            foreach ([1, 2, 3, 5, 10, 20, 50] as $limit) {
                ini_set('pcre.' . $setting, (string) $limit);
                $fromArray = CompiledRoutes::fromArray($map);
                $fromFile = CompiledRoutes::fromCache($file);
                foreach (['/', '/a', '/a/a', '/a/b', '/a/abc/abc', '/static', '/missing/no'] as $uri) {
                    foreach (['GET', 'DELETE'] as $method) {
                        $expected = $observe($source, $uri, $method);
                        expect($observe($fromArray, $uri, $method))->toBe($expected)
                            ->and($observe($fromFile, $uri, $method))->toBe($expected);
                    }
                }
            }
            ini_set('pcre.' . $setting, '100000');
        }

        // A syntactically valid but unrelated expression is not a valid
        // representation of this contextual constraint even if resealed.
        $changed = $map;
        $changed['routeData']['context']['regex'] = '#\A(?:/unrelated)\z#';
        expect(CompiledRoutes::tryFromArray(PreparedMap::seal($changed)))->toBeNull();
    } finally {
        ini_set('pcre.backtrack_limit', (string) $oldBacktrack);
        ini_set('pcre.recursion_limit', (string) $oldRecursion);
        ini_set('pcre.jit', (string) $oldJit);
        unlink($file);
    }
})->with([
    '/{first:(?:a?){20}?}/{second:(?P=first)?}',
    '/{first:(?:a?){20}?}/{second:(?P=first)?}/{third?}',
    '/{first?}/{second:(?P=first)?}',
    '/{first?}/{second:(?P=first)}',
    '/a/{first}/{second:(?P=first)}',
    '/[?first]/[?second:(?P=first)]',
]);


it('keeps first source requests and prepared requests equivalent under changed PCRE limits', function (string $jit): void {
    $previous = [
        'pcre.jit' => ini_get('pcre.jit'),
        'pcre.backtrack_limit' => ini_get('pcre.backtrack_limit'),
        'pcre.recursion_limit' => ini_get('pcre.recursion_limit'),
    ];
    $observe = static function (callable $operation): array {
        try {
            return ['result', $operation()];
        } catch (RouterException|InvalidArgumentException $error) {
            return [$error::class, $error->getMessage()];
        }
    };
    $match = static function ($routes, string $uri, string $method): array {
        $result = $routes->match($routes, $uri, $method);
        return [$result->name, $result->parameters, $result->route->toArray()];
    };

    try {
        ini_set('pcre.jit', $jit);
        foreach ([
            '/{a?}', '/[?a]', '/<a?>', '/:a?',
            '/{a?}/{b?}', '/{a?}/{b?}/{c?}/{d?}/{e?}',
            '/{a:(?:a?){20}?}/{b:(?P=a)?}',
            '/{value:(?:a?){20}}', '/a/{value?}', '/{a?}{b:a*}',
            '/{a?}{b:(*ACCEPT)?}/end', '/a/{id}', '/a/{value:a|b}',
        ] as $pattern) {
            foreach (['pcre.backtrack_limit', 'pcre.recursion_limit'] as $setting) {
                foreach ([1, 2, 3, 5, 10, 20, 50, 200] as $limit) {
                    ini_set('pcre.backtrack_limit', '1000000');
                    ini_set('pcre.recursion_limit', '100000');
                    $records = [
                        RouteRecord::get('dynamic', $pattern, 'H'),
                        RouteRecord::get('static', '/static', 'H'),
                    ];
                    $fresh = static fn (): Routes => Routes::fromResolved($records);
                    $cached = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($fresh()));
                    ini_set($setting, (string) $limit);

                    foreach (['/static', '/', '/a', '/a/a', '/missing', '/a/42'] as $uri) {
                        foreach (['GET', 'DELETE'] as $method) {
                            // Never warm the source index before the operation.
                            $source = $fresh();
                            expect($observe(fn () => $match($source, $uri, $method)))
                                ->toBe($observe(fn () => $match($cached, $uri, $method)));
                            expect(ini_get($setting))->toBe((string) $limit);
                        }
                    }
                    foreach ([[], ['a' => 'a', 'b' => 'a', 'value' => 'a', 'id' => 42]] as $parameters) {
                        $source = $fresh();
                        expect($observe(fn () => $source->generate($source, 'dynamic', $parameters)))
                            ->toBe($observe(fn () => $cached->generate($cached, 'dynamic', $parameters)));
                    }
                    expect(ini_get($setting))->toBe((string) $limit);
                }
            }
        }

        ini_set('pcre.backtrack_limit', '7');
        ini_set('pcre.recursion_limit', '13');
        expect(fn () => (new \Componenta\Http\Router\Compiler())->compile('/{x:(?P=unknown)}'))
            ->toThrow(\Componenta\Http\Router\Exception\RoutePatternException::class);
        expect(ini_get('pcre.backtrack_limit'))->toBe('7')
            ->and(ini_get('pcre.recursion_limit'))->toBe('13')
            ->and(ini_get('pcre.jit'))->toBe($jit);
    } finally {
        foreach ($previous as $key => $value) { ini_set($key, (string) $value); }
    }
})->with(['0', '1']);
