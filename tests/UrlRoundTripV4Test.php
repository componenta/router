<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('generates and matches contextual regex constraints using the same complete expression', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('pair', '/pair/{value}', 'Handler', tokens: ['value' => '(a)(b)\2']));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        $path = $routes->generate($routes, 'pair', ['value' => 'aba']);
        expect($path)->toBe('/pair/aba')
            ->and($routes->match($routes, $path, 'GET')->parameters)->toBe(['value' => 'aba']);
        expect(fn () => $routes->generate($routes, 'pair', ['value' => 'abb']))->toThrow(InvalidArgumentException::class);
    }
});

it('rejects an ambiguous optional parameter placement', function (string $pattern): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', $pattern, 'Handler'));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        expect(fn () => $routes->generate($routes, 'item', ['right' => 'b']))->toThrow(InvalidArgumentException::class);
        $path = $routes->generate($routes, 'item', ['left' => 'a', 'right' => 'b']);
        expect($path)->toBe('/items/a/b')
            ->and($routes->match($routes, $path, 'GET')->parameters)->toBe(['left' => 'a', 'right' => 'b']);
    }
})->with(['/items/{left?}/{right?}', '/items/[?left]/[?right]', '/items/<left?>/<right?>', '/items/:left?/:right?']);

it('does not collapse an all-slash route to an empty path', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('root', '///', 'Handler'));
    foreach ([$source, CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source))] as $routes) {
        expect($routes->getRoute('root')->path)->toBe('/')
            ->and($routes->generate($routes, 'root'))->toBe('/')
            ->and($routes->match($routes, '/', 'GET')->name)->toBe('root');
    }
});
