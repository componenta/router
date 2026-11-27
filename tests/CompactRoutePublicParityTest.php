<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves the complete public route record in a compact cache', function (): void {
    $tokens = [
        'id' => '\\d+',
        'reserved_for_generator' => '[a-z]+',
    ];
    $expectedTokens = $tokens;
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get(
        'users.show',
        '/users/{id}',
        'ShowUserController',
        tokens: $tokens,
    ));
    $file = tempnam(sys_get_temp_dir(), 'componenta-router-parity-');

    if ($file === false) {
        throw new RuntimeException('Unable to create a temporary route cache.');
    }

    try {
        (new RouteCacheGenerator())->generate($routes, $file);
        $cache = require $file;
        $compiled = CompiledRoutes::fromCache($file);

        expect($cache['version'])->toBe(RouteCacheGenerator::CACHE_VERSION)
            ->and($cache)->not->toHaveKey('defaultTokens')
            ->and($cache['routeData']['users.show']['tokens'])->toBe([
                'reserved_for_generator' => '[a-z]+',
            ])
            ->and($compiled->getRoute('users.show')->tokens)->toBe($expectedTokens)
            ->and($compiled->toArray()['users.show']->tokens)->toBe($expectedTokens)
            ->and(iterator_to_array($compiled)['users.show']->tokens)->toBe($expectedTokens);
    } finally {
        @unlink($file);
    }
});

it('uses custom compiler defaults for generation while preserving explicit public tokens', function (): void {
    $compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);
    $generator = new RouteCacheGenerator($compiler);
    $routes = new Routes($compiler);
    $routes->addRoute(RouteRecord::get(
        'users.show',
        '/users/{id}',
        'ShowUserController',
    ));
    $file = tempnam(sys_get_temp_dir(), 'componenta-router-custom-defaults-');

    if ($file === false) {
        throw new RuntimeException('Unable to create a temporary route cache.');
    }

    try {
        $generator->generate($routes, $file);
        $cache = require $file;
        $compiled = CompiledRoutes::fromCache($file);

        expect($cache['defaultTokens'])->toBe($compiler->compile('/')->tokens)
            ->and($cache['routeData']['users.show'])->not->toHaveKey('tokens')
            ->and($compiled->getRoute('users.show')->tokens)->toBe(
                $routes->getRoute('users.show')->tokens,
            )
            ->and($compiled->generate($compiled, 'users.show', ['id' => 'ABC']))->toBe('/users/ABC');

        expect(fn (): string => $compiled->generate($compiled, 'users.show', ['id' => '123']))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($file);
    }
});

it('preserves numeric route names and native collection keys across source and file cache', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('42', '/answer', 'AnswerHandler'));
    $source->addRoute(RouteRecord::get('042', '/answer/{id}', 'ItemHandler'));
    $source->addRoute(RouteRecord::get('0', '/zero/{id}', 'ZeroHandler'));
    $source->addRoute(RouteRecord::get('-7', '/negative', 'NegativeHandler'));

    $generator = new RouteCacheGenerator();
    $file = tempnam(sys_get_temp_dir(), 'router_numeric_names_');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }
    try {
        $generator->generate($source, $file);
        $expectedKeys = [42, '042', 0, -7];
        foreach ([$source, CompiledRoutes::fromArray($generator->compile($source)), CompiledRoutes::fromCache($file)] as $routes) {
            expect(array_keys($routes->toArray()))->toBe($expectedKeys)
                ->and(array_keys(iterator_to_array($routes)))->toBe($expectedKeys)
                ->and(count($routes))->toBe(4);
            foreach ([
                ['42', '/answer', []],
                ['042', '/answer/9', ['id' => 9]],
                ['0', '/zero/1', ['id' => 1]],
                ['-7', '/negative', []],
            ] as [$name, $path, $parameters]) {
                expect($routes->has($name))->toBeTrue()
                    ->and($routes->getRoute($name)->name)->toBe($name)
                    ->and($routes->match($routes, $path, 'GET')->name)->toBe($name)
                    ->and($routes->generate($routes, $name, $parameters))->toBe($path);
            }
        }
    } finally {
        unlink($file);
    }
});
