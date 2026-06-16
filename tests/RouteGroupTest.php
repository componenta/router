<?php

declare(strict_types=1);

use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

beforeEach(function () {
    $this->routes = new Routes();
});

it('applies group prefix to route path', function () {
    $group = $this->routes->group('api', '/api/v1');
    $group->get('users', '/users', 'handler');

    $route = $this->routes->getRoute('api.users');

    expect($route->path)->toBe('/api/v1/users');
});

it('applies group name prefix to route name', function () {
    $group = $this->routes->group('api', '/api/v1');
    $group->get('users', '/users', 'handler');

    expect($this->routes->has('api.users'))->toBeTrue();
});

it('matches grouped route', function () {
    $group = $this->routes->group('api', '/api/v1');
    $group->get('users', '/users/{id}', 'handler');

    $result = $this->routes->match($this->routes, '/api/v1/users/42', 'GET');

    expect($result->name)->toBe('api.users')
        ->and($result->parameters)->toBe(['id' => 42]);
});

it('matches grouped route with optional parameter', function () {
    $group = $this->routes->group('api', '/api/v1');
    $group->get('posts', '/posts/{id?}', 'handler');

    $without = $this->routes->match($this->routes, '/api/v1/posts', 'GET');
    $with = $this->routes->match($this->routes, '/api/v1/posts/5', 'GET');

    expect($without->name)->toBe('api.posts')
        ->and($without->parameters)->toBe([])
        ->and($with->parameters)->toBe(['id' => 5]);
});

it('supports nested groups', function () {
    $api = $this->routes->group('api', '/api/v1');
    $admin = $api->group('admin', '/admin');
    $admin->get('users', '/users', 'handler');

    $result = $this->routes->match($this->routes, '/api/v1/admin/users', 'GET');

    expect($result->name)->toBe('api.admin.users');
});

it('inherits group tokens', function () {
    $group = $this->routes->group('api', '/api/v1', tokens: ['id' => '\d+']);
    $group->get('users', '/users/{id}', 'handler');

    $result = $this->routes->match($this->routes, '/api/v1/users/42', 'GET');

    expect($result->parameters)->toBe(['id' => 42]);
});

it('adds route via addRoute with group attribute', function () {
    $this->routes->group('api', '/api/v1');

    $this->routes->addRoute(new RouteRecord(
        name: 'posts',
        path: '/posts',
        handler: 'handler',
        group: 'api',
    ));

    $result = $this->routes->match($this->routes, '/api/v1/posts', 'GET');

    expect($result->name)->toBe('api.posts');
});
