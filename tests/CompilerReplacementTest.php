<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('keeps compiler configuration immutable across cold and warm matching', function (bool $cached): void {
    $compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemHandler'));
    $routes = $cached ? CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source)) : $source;
    $replace = static function () use ($routes): void {
        $routes->compiler = new Compiler(defaultPatterns: ['id' => '[a-z]+']);
    };

    expect($replace)->toThrow(Error::class);
    for ($i = 0; $i < 2; ++$i) {
        expect($routes->generate($routes, 'item', ['id' => 'ABC']))->toBe('/items/ABC')
            ->and($routes->match($routes, '/items/ABC', 'GET')->parameters)->toBe(['id' => 'ABC'])
            ->and(fn () => $routes->generate($routes, 'item', ['id' => 'abc']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $routes->match($routes, '/items/abc', 'GET'))->toThrow(RouteNotFoundException::class)
            ->and($replace)->toThrow(Error::class);
    }
})->with(['source' => false, 'prepared' => true]);
