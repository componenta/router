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


it('snapshots group definitions before caller-owned reference slots can change', function (): void {
    $source = new Routes();
    $token = '[A-Z]+';
    $page = 1;
    $group = $source->group('api', '/api', tokens: ['id' => &$token], defaults: ['page' => &$page]);
    $token = '[a-z]+';
    $page = 2;

    expect($group->tokens)->toBe(['id' => '[A-Z]+'])
        ->and($group->defaults)->toBe(['page' => 1]);

    $group->get('first', '/first/{id}', 'Handler');
    $group->group('nested', '/nested')->get('second', '/{id}', 'Handler');

    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $prepared] as $routes) {
        foreach (['/api/first/ABC', '/api/nested/ABC'] as $path) {
            expect($routes->match($routes, $path, 'GET')->parameters)->toBe(['page' => 1, 'id' => 'ABC']);
        }
        expect($routes->generate($routes, 'api.nested.second', ['id' => 'ABC']))->toBe('/api/nested/ABC');
    }
});

it('rejects invalid group configuration before registering a group', function (): void {
    $source = new Routes();
    expect(fn () => $source->group('invalid', '/invalid', tokens: ['id' => '[']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $source->group('invalid', '/invalid', defaults: ['id' => []]))
        ->toThrow(InvalidArgumentException::class);
    expect($source->groups->has('invalid'))->toBeFalse();
});


it('keeps cloned route collections and their nested group handles independent', function (bool $warm): void {
    $source = new Routes();
    $api = $source->group('api', '/api', middleware: ['api'], defaults: ['scope' => 'read']);
    $admin = $api->group('admin', '/admin', middleware: ['auth'], tokens: ['id' => '[A-Z]+']);
    $admin->get('existing', '/existing/{id}', 'Handler');

    if ($warm) {
        expect($source->match($source, '/api/admin/existing/ABC', 'GET')->name)->toBe('api.admin.existing');
    }

    $copy = clone $source;
    $copiedApi = $copy->groups->get('api');
    $copiedAdmin = $copy->groups->get('api.admin');

    expect($copy->groups)->not->toBe($source->groups)
        ->and($copiedApi)->not->toBe($api)
        ->and($copiedAdmin)->not->toBe($admin)
        ->and($copiedAdmin->parent)->toBe($copiedApi)
        ->and($copy->getRoute('api.admin.existing'))->toBe($source->getRoute('api.admin.existing'));

    $copiedAdmin->get('copy', '/copy/{id}', 'Handler');
    $copiedApi->group('copy-only', '/copy-only')->get('home', '/', 'Handler');
    $copy->group('separate', '/separate')->get('home', '/', 'Handler');
    $admin->get('original', '/original/{id}', 'Handler');

    expect($source->has('api.admin.copy'))->toBeFalse()
        ->and($source->groups->has('api.copy-only'))->toBeFalse()
        ->and($source->groups->has('separate'))->toBeFalse()
        ->and($copy->has('api.admin.original'))->toBeFalse()
        ->and($source)->toHaveCount(2)
        ->and($copy)->toHaveCount(4);

    foreach ([$copy, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($copy))] as $routes) {
        $match = $routes->match($routes, '/api/admin/copy/ABC', 'GET');
        expect($match->parameters)->toBe(['scope' => 'read', 'id' => 'ABC'])
            ->and($match->middlewares?->toArray())->toBe(['api', 'auth'])
            ->and($routes->generate($routes, 'api.admin.copy', ['id' => 'ABC']))->toBe('/api/admin/copy/ABC')
            ->and($routes->match($routes, '/api/copy-only', 'GET')->name)->toBe('api.copy-only.home')
            ->and($routes->match($routes, '/separate', 'GET')->name)->toBe('separate.home')
            ->and(fn () => $routes->match($routes, '/api/admin/original/ABC', 'GET'))
            ->toThrow(\Componenta\Http\Router\Exception\RouteNotFoundException::class);
    }
    expect($source->match($source, '/api/admin/original/ABC', 'GET')->name)->toBe('api.admin.original')
        ->and(fn () => $source->match($source, '/api/admin/copy/ABC', 'GET'))
        ->toThrow(\Componenta\Http\Router\Exception\RouteNotFoundException::class);
})->with(['cold' => false, 'warm' => true]);
