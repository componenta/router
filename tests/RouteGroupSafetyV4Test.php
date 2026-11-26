<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\GroupNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('rejects an unregistered group rather than silently dropping its middleware', function (): void {
    $routes = new Routes();
    expect(fn () => $routes->addRoute(RouteRecord::get('dashboard', '/dashboard', 'Controller')->withGroup('admin')))
        ->toThrow(GroupNotFoundException::class);
    expect($routes)->toHaveCount(0);
});

it('rejects duplicate group registration and NUL route names', function (): void {
    $routes = new Routes();
    $routes->group('admin', '/admin', middleware: ['auth']);
    expect(fn () => $routes->group('admin', '/other'))->toThrow(LogicException::class);
    expect(fn () => RouteRecord::get("\0admin.dashboard", '/dashboard', 'Controller'))
        ->toThrow(InvalidArgumentException::class);
});

it('applies nested group middleware once in both source and prepared routing', function (): void {
    $source = new Routes();
    $source->group('api', '/api', middleware: ['api'])->group('admin', '/admin', middleware: ['auth'])
        ->get('dashboard', '/dashboard', 'Controller');
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $prepared] as $routes) {
        $match = $routes->match($routes, '/api/admin/dashboard', 'GET');
        expect($match->name)->toBe('api.admin.dashboard')
            ->and($match->middlewares?->toArray())->toBe(['api', 'auth'])
            ->and($match->route->group)->toBe('api.admin');
    }
});
