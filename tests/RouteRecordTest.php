<?php

declare(strict_types=1);

use Componenta\Http\Router\RouteRecord;

// --- Path normalization ---

it('prepends leading slash', function () {
    $route = RouteRecord::get('test', 'users', 'handler');

    expect($route->path)->toBe('/users');
});

it('strips trailing slash', function () {
    $route = RouteRecord::get('test', '/users/', 'handler');

    expect($route->path)->toBe('/users');
});

it('collapses double slashes', function () {
    $route = RouteRecord::get('test', '/users//list', 'handler');

    expect($route->path)->toBe('/users/list');
});

it('keeps root path as slash', function () {
    $route = RouteRecord::get('test', '/', 'handler');

    expect($route->path)->toBe('/');
});

// --- Method normalization ---

it('normalizes methods to uppercase', function () {
    $route = new RouteRecord('test', '/', 'handler', ['get', 'post']);

    expect($route->methods)->toBe(['GET', 'POST']);
});

it('deduplicates methods', function () {
    $route = new RouteRecord('test', '/', 'handler', ['GET', 'get', 'GET']);

    expect($route->methods)->toBe(['GET']);
});

it('throws on empty methods', function () {
    expect(fn () => new RouteRecord('test', '/', 'handler', []))
        ->toThrow(InvalidArgumentException::class);
});

// --- allow() ---

it('checks if method is allowed', function () {
    $route = RouteRecord::get('test', '/', 'handler');

    expect($route->allow('GET'))->toBeTrue()
        ->and($route->allow('get'))->toBeTrue()
        ->and($route->allow('POST'))->toBeFalse();
});

// --- Token validation ---

it('throws on invalid regex token', function () {
    set_error_handler(static fn () => true);

    try {
        expect(fn () => RouteRecord::get('test', '/', 'handler', tokens: ['id' => '[invalid']))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        restore_error_handler();
    }
});

// --- Defaults validation ---

it('throws on non-scalar default value', function () {
    expect(fn () => RouteRecord::get('test', '/', 'handler', defaults: ['id' => []]))
        ->toThrow(InvalidArgumentException::class);
});

// --- Factory methods ---

it('creates route for each HTTP method', function (string $method, string $factoryMethod) {
    $route = RouteRecord::$factoryMethod('test', '/', 'handler');

    expect($route->methods)->toBe([strtoupper($method)]);
})->with([
    ['GET', 'get'],
    ['POST', 'post'],
    ['PUT', 'put'],
    ['DELETE', 'delete'],
    ['PATCH', 'patch'],
    ['HEAD', 'head'],
    ['OPTIONS', 'options'],
]);

it('creates any-method route', function () {
    $route = RouteRecord::any('test', '/', 'handler');

    expect($route->allow('GET'))->toBeTrue()
        ->and($route->allow('POST'))->toBeTrue()
        ->and($route->allow('PUT'))->toBeTrue()
        ->and($route->allow('DELETE'))->toBeTrue()
        ->and($route->allow('PATCH'))->toBeTrue()
        ->and($route->allow('HEAD'))->toBeTrue()
        ->and($route->allow('OPTIONS'))->toBeTrue()
        ->and($route->methods)->toHaveCount(7);
});

// --- Serialization ---

it('roundtrips through toArray/fromArray', function () {
    $original = RouteRecord::get('test', '/users/{id}', 'handler', tokens: ['id' => '\d+'], defaults: ['id' => 1]);

    $restored = RouteRecord::fromArray($original->toArray());

    expect($restored->name)->toBe($original->name)
        ->and($restored->path)->toBe($original->path)
        ->and($restored->methods)->toBe($original->methods)
        ->and($restored->tokens)->toBe($original->tokens)
        ->and($restored->defaults)->toBe($original->defaults);
});
