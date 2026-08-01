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
        $compiled = CompiledRoutes::fromCache($file);

        expect($compiled->getRoute('users.show')->tokens)->toBe($expectedTokens)
            ->and($compiled->toArray()['users.show']->tokens)->toBe($expectedTokens)
            ->and(iterator_to_array($compiled)['users.show']->tokens)->toBe($expectedTokens);
    } finally {
        @unlink($file);
    }
});
