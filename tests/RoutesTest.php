<?php

declare(strict_types=1);

use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

beforeEach(function () {
    $this->routes = new Routes();
});

// --- Static route matching ---

it('matches static route', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    $result = $this->routes->match($this->routes, '/', 'GET');

    expect($result->name)->toBe('home')
        ->and($result->parameters)->toBe([]);
});

it('normalizes uri leading slash', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    $result = $this->routes->match($this->routes, '', 'GET');

    expect($result->name)->toBe('home');
});

// --- Dynamic route matching ---

it('matches required parameter', function () {
    $this->routes->addRoute(RouteRecord::get('user', '/users/{id}', 'handler'));

    $result = $this->routes->match($this->routes, '/users/42', 'GET');

    expect($result->name)->toBe('user')
        ->and($result->parameters)->toBe(['id' => 42]);
});

it('casts numeric parameter to int', function () {
    $this->routes->addRoute(RouteRecord::get('user', '/users/{id}', 'handler'));

    $result = $this->routes->match($this->routes, '/users/123', 'GET');

    expect($result->parameters['id'])->toBe(123)
        ->and($result->parameters['id'])->toBeInt();
});

it('keeps string parameter as string', function () {
    $this->routes->addRoute(RouteRecord::get('post', '/posts/{slug}', 'handler', tokens: ['slug' => '[a-z0-9-]+']));

    $result = $this->routes->match($this->routes, '/posts/hello-world', 'GET');

    expect($result->parameters['slug'])->toBe('hello-world')
        ->and($result->parameters['slug'])->toBeString();
});

// --- Optional parameter matching ---

it('matches route without optional parameter', function () {
    $this->routes->addRoute(RouteRecord::get('users', '/users/{id?}', 'handler'));

    $result = $this->routes->match($this->routes, '/users', 'GET');

    expect($result->name)->toBe('users')
        ->and($result->parameters)->toBe([]);
});

it('matches route with optional parameter present', function () {
    $this->routes->addRoute(RouteRecord::get('users', '/users/{id?}', 'handler'));

    $result = $this->routes->match($this->routes, '/users/42', 'GET');

    expect($result->name)->toBe('users')
        ->and($result->parameters)->toBe(['id' => 42]);
});

it('matches mixed required and optional parameters', function () {
    $this->routes->addRoute(RouteRecord::get('posts', '/users/{id}/{page?}', 'handler'));

    $without = $this->routes->match($this->routes, '/users/5', 'GET');
    $with = $this->routes->match($this->routes, '/users/5/3', 'GET');

    expect($without->parameters)->toBe(['id' => 5])
        ->and($with->parameters)->toBe(['id' => 5, 'page' => 3]);
});

it('uses default value for absent optional parameter', function () {
    $this->routes->addRoute(RouteRecord::get('posts', '/posts/{page?}', 'handler', defaults: ['page' => 1]));

    $result = $this->routes->match($this->routes, '/posts', 'GET');

    expect($result->parameters)->toBe(['page' => 1]);
});

// --- Exception handling ---

it('throws RouteNotFoundException for unknown uri', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    expect(fn () => $this->routes->match($this->routes, '/unknown', 'GET'))
        ->toThrow(RouteNotFoundException::class);
});

it('throws MethodNotAllowedException for wrong method', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    expect(fn () => $this->routes->match($this->routes, '/', 'POST'))
        ->toThrow(MethodNotAllowedException::class);
});

it('throws MethodNotAllowedException for wrong method on dynamic route', function () {
    $this->routes->addRoute(RouteRecord::get('user', '/users/{id}', 'handler'));

    expect(fn () => $this->routes->match($this->routes, '/users/1', 'DELETE'))
        ->toThrow(MethodNotAllowedException::class);
});

// --- URL generation ---

it('generates url for static route', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    expect($this->routes->generate($this->routes, 'home'))->toBe('/');
});

it('generates url with required parameter', function () {
    $this->routes->addRoute(RouteRecord::get('user', '/users/{id}', 'handler'));

    expect($this->routes->generate($this->routes, 'user', ['id' => 42]))->toBe('/users/42');
});

it('generates url with optional parameter present', function () {
    $this->routes->addRoute(RouteRecord::get('users', '/users/{id?}', 'handler'));

    expect($this->routes->generate($this->routes, 'users', ['id' => 42]))->toBe('/users/42');
});

it('generates url with optional parameter absent', function () {
    $this->routes->addRoute(RouteRecord::get('users', '/users/{id?}', 'handler'));

    expect($this->routes->generate($this->routes, 'users'))->toBe('/users');
});

// --- Route collection ---

it('checks route existence by name', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    expect($this->routes->has('home'))->toBeTrue()
        ->and($this->routes->has('missing'))->toBeFalse();
});

it('counts routes', function () {
    $this->routes->addRoute(RouteRecord::get('a', '/a', 'h'));
    $this->routes->addRoute(RouteRecord::get('b', '/b', 'h'));

    expect($this->routes->count())->toBe(2);
});

it('throws on duplicate route name', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));

    expect(fn () => $this->routes->addRoute(RouteRecord::get('home', '/other', 'handler')))
        ->toThrow(\Componenta\Http\Router\Exception\RouteAlreadyExistsException::class);
});

it('retrieves route by name', function () {
    $this->routes->addRoute(RouteRecord::get('user', '/users/{id}', 'handler'));

    $route = $this->routes->getRoute('user');

    expect($route->name)->toBe('user')
        ->and($route->path)->toBe('/users/{id}');
});

it('throws RouteNotRegisteredException for unknown name in getRoute', function () {
    expect(fn () => $this->routes->getRoute('missing'))
        ->toThrow(RouteNotRegisteredException::class);
});

it('throws RouteNotRegisteredException for unknown name in generate', function () {
    expect(fn () => $this->routes->generate($this->routes, 'missing'))
        ->toThrow(RouteNotRegisteredException::class);
});

it('iterates over all routes', function () {
    $this->routes->addRoute(RouteRecord::get('home', '/', 'handler'));
    $this->routes->addRoute(RouteRecord::get('user', '/users/{id}', 'handler'));

    $names = [];
    foreach ($this->routes as $name => $route) {
        $names[] = $name;
    }

    expect($names)->toBe(['home', 'user']);
});
