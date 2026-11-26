<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('encodes route values without adding query, fragment or authority', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{value}', 'Controller'));
    $source->addRoute(RouteRecord::get('catch', '/files/{any}', 'Controller'));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        $url = $routes->generate($routes, 'item', ['value' => 'a?b#c%']);
        expect($url)->toBe('/items/a%3Fb%23c%25')
            ->and($routes->match($routes, $url, 'GET')->name)->toBe('item')
            ->and($routes->generate($routes, 'catch', ['any' => 'a/b?c']))->toBe('/files/a/b%3Fc');
        expect(fn () => $routes->generate($routes, 'catch', ['any' => '../private']))->toThrow(InvalidArgumentException::class);
        expect(fn () => $routes->generate($routes, 'catch', ['any' => '/example.com']))->toThrow(InvalidArgumentException::class);
    }
});

it('supports an optional parameter at the root without generating an empty URI', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('root', '/{value?}', 'Controller'));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        expect($routes->generate($routes, 'root'))->toBe('/')
            ->and($routes->match($routes, '/', 'GET')->name)->toBe('root');
    }
});

it('keeps extension HTTP methods case-sensitive', function (): void {
    $source = new Routes();
    $source->addRoute(new RouteRecord('custom', '/custom', 'Controller', ['custom']));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        expect($routes->match($routes, '/custom', 'custom')->name)->toBe('custom');
        expect(fn () => $routes->match($routes, '/custom', 'CUSTOM'))->toThrow(MethodNotAllowedException::class);
    }
    expect(fn () => new RouteRecord('bad', '/bad', 'Controller', ["GET\r\n"]))->toThrow(InvalidArgumentException::class);
});


it('preserves literal empty segments and slash sequences inside inline constraints', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('literal', '/a//b', 'Literal'));
    $source->addRoute(RouteRecord::get('ordinary', '/a/b', 'Ordinary'));
    $source->addRoute(RouteRecord::get('inline', '/files/{value:a//b}', 'Inline'));
    $source->addRoute(RouteRecord::get('optional', '/optional//{id?}', 'Optional'));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $prepared] as $routes) {
        expect($routes->match($routes, '/a//b', 'GET')->name)->toBe('literal')
            ->and($routes->match($routes, '/a/b', 'GET')->name)->toBe('ordinary')
            ->and($routes->generate($routes, 'literal'))->toBe('/a//b')
            ->and($routes->match($routes, '/files/a//b', 'GET')->parameters)->toBe(['value' => 'a//b'])
            ->and($routes->generate($routes, 'inline', ['value' => 'a//b']))->toBe('/files/a//b')
            ->and($routes->generate($routes, 'optional'))->toBe('/optional/')
            ->and($routes->match($routes, '/optional/', 'GET')->name)->toBe('optional');
    }
});
