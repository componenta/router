<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('matches wildcard chunks together with literal prefix chunks in registration order', function (): void {
    $routes = new Routes();

    for ($index = 0; $index < 50; $index++) {
        $routes->addRoute(RouteRecord::get(
            'wildcard.' . $index,
            '/{scope}/wild' . $index . '/{id}',
            'WildcardController',
        ));
    }

    for ($index = 0; $index < 4_951; $index++) {
        $path = $index === 0
            ? '/posts/wild0/{id}'
            : '/posts/catalog' . $index . '/{id}';
        $routes->addRoute(RouteRecord::get(
            'literal.' . $index,
            $path,
            'LiteralController',
        ));
    }

    $file = tempnam(sys_get_temp_dir(), 'componenta-router-chunks-');

    if ($file === false) {
        throw new RuntimeException('Unable to create a temporary route cache.');
    }

    try {
        (new RouteCacheGenerator())->generate($routes, $file);
        $compiled = CompiledRoutes::fromCache($file);

        $expected = $routes->match($routes, '/posts/wild0/42', 'GET');
        $actual = $compiled->match($compiled, '/posts/wild0/42', 'GET');

        expect($actual->name)->toBe($expected->name)
            ->and($actual->name)->toBe('wildcard.0')
            ->and($actual->parameters)->toBe(['scope' => 'posts', 'id' => 42]);
    } finally {
        @unlink($file);
    }
});
