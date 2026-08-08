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
    $expectedTokens = (new Compiler())->compile('/users/{id}', $tokens)->tokens;
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

it('stores custom compiler defaults once and restores them for public routes', function (): void {
    $compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);
    $generator = new RouteCacheGenerator($compiler);
    $routes = new Routes();
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
                $compiler->compile('/users/{id}')->tokens,
            )
            ->and($compiled->generate($compiled, 'users.show', ['id' => 'ABC']))->toBe('/users/ABC');

        expect(fn (): string => $compiled->generate($compiled, 'users.show', ['id' => '123']))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($file);
    }
});
