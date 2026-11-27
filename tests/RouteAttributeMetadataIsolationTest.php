<?php

declare(strict_types=1);

use Componenta\Http\Router\Attribute\Route;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('detaches caller references in readonly route metadata before creating route records', function (): void {
    $method = 'GET';
    $middleware = 'Auth';
    $pattern = '[a-z]+';
    $page = 1;
    $attribute = new Route('item', '/items/{value}', [&$method], [&$middleware],
        ['value' => &$pattern], ['page' => &$page]);

    $copy = clone $attribute;
    $method = 'POST';
    $middleware = 'Other';
    $pattern = '.*';
    $page = 2;

    foreach ([$attribute, $copy] as $metadata) {
        expect($metadata->methods)->toBe(['GET'])
            ->and($metadata->middlewares)->toBe(['Auth'])
            ->and($metadata->tokens)->toBe(['value' => '[a-z]+'])
            ->and($metadata->defaults)->toBe(['page' => 1]);

        $record = new RouteRecord($metadata->name, $metadata->path, 'Handler',
            $metadata->methods, $metadata->middlewares, $metadata->tokens, $metadata->defaults, $metadata->group);
        $source = (new Routes())->addRoute($record);
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
        foreach ([$source, $prepared] as $routes) {
            $match = $routes->match($routes, '/items/abc', 'GET');
            expect($match->parameters)->toBe(['page' => 1, 'value' => 'abc'])
                ->and($match->middlewares?->toArray())->toBe(['Auth'])
                ->and(fn () => $routes->match($routes, '/items/abc', 'POST'))
                ->toThrow(\Componenta\Http\Router\Exception\MethodNotAllowedException::class)
                ->and(fn () => $routes->match($routes, '/items/123', 'GET'))
                ->toThrow(\Componenta\Http\Router\Exception\RouteNotFoundException::class);
        }
    }
});

it('keeps shared metadata slots isolated without deep-copying nested middleware payloads', function (): void {
    $shared = 'GET';
    $value = 'old';
    $object = new stdClass();
    $payload = ['value' => &$value];
    $metadata = new Route('item', '/', [&$shared, &$shared],
        [$object, $payload], ['value' => &$shared], ['value' => &$shared]);

    $shared = 'POST';
    $value = 'new';
    expect($metadata->methods)->toBe(['GET', 'GET'])
        ->and($metadata->tokens)->toBe(['value' => 'GET'])
        ->and($metadata->defaults)->toBe(['value' => 'GET'])
        ->and($metadata->middlewares[0])->toBe($object)
        ->and($metadata->middlewares[1]['value'])->toBe('new');
});

it('preserves reflected route attributes and inherited metadata construction', function (): void {
    $controller = new #[Route('reflected', '/item', 'GET|HEAD', tokens: ['id' => '\d+'], defaults: ['page' => 3], priority: 12)] class {};
    $metadata = (new ReflectionClass($controller))->getAttributes(Route::class)[0]->newInstance();
    expect($metadata->methods)->toBe(['GET', 'HEAD'])
        ->and($metadata->tokens)->toBe(['id' => '\d+'])
        ->and($metadata->defaults)->toBe(['page' => 3])
        ->and($metadata->priority)->toBe(12);

    $method = 'HEAD';
    $derived = new readonly class('derived', '/', [&$method]) extends Route {};
    $method = 'POST';
    expect($derived->methods)->toBe(['HEAD']);
});
