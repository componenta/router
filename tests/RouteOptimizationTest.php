<?php
declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('reports every allowed method in the same order for overlapping static and dynamic routes', function (): void {
    $routes = new Routes();
    $routes->addRoute(new RouteRecord('static', '/items/new', 'StaticHandler', ['GET']));
    $routes->addRoute(new RouteRecord('dynamic', '/items/[value]', 'DynamicHandler', ['POST']));
    $file = tempnam(sys_get_temp_dir(), 'routes-optimization-');
    try {
        file_put_contents($file, '<?php return ' . var_export((new RouteCacheGenerator())->compile($routes), true) . ';');
        foreach ([$routes, CompiledRoutes::fromCache($file)] as $collection) {
            try {
                $collection->match($collection, '/items/new', 'DELETE');
                $this->fail('Expected MethodNotAllowedException');
            } catch (MethodNotAllowedException $e) {
                expect($e->allowedMethods)->toBe(['GET', 'POST'])
                    ->and($e->allowHeader)->toBe('GET, POST');
            }
        }
    } finally {
        unlink($file);
    }
});

it('preserves the public record including original path tokens and defaults after building', function (): void {
    $routes = new Routes();
    $route = new RouteRecord('show', '/items/[id]', 'ShowHandler', ['GET'], [], ['id' => '[0-9]+'], ['unused' => 1.0]);
    $routes->addRoute($route);
    $file = tempnam(sys_get_temp_dir(), 'routes-record-');
    try {
        file_put_contents($file, '<?php return ' . var_export((new RouteCacheGenerator())->compile($routes), true) . ';');
        $compiled = CompiledRoutes::fromCache($file);
        expect($compiled->getRoute('show')->toArray())->toBe($route->toArray())
            ->and($compiled->match($compiled, '/items/42', 'GET')->route->toArray())->toBe($route->toArray());
    } finally {
        unlink($file);
    }
});

it('exposes HTTP error codes before and after route optimization', function (string $uri, string $method, string $exception, int $status): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('home', '/', 'HomeHandler'));
    $file = tempnam(sys_get_temp_dir(), 'route-status-');
    try {
        file_put_contents($file, '<?php return ' . var_export((new RouteCacheGenerator())->compile($routes), true) . ';');
        foreach ([$routes, CompiledRoutes::fromCache($file)] as $matcher) {
            try {
                $matcher->match($matcher, $uri, $method);
                $this->fail('Expected a routing exception.');
            } catch (\Componenta\Http\Router\Exception\RouterException $error) {
                expect($error)->toBeInstanceOf($exception)
                    ->and($error->getCode())->toBe($status);
            }
        }
    } finally {
        unlink($file);
    }
})->with([
    'missing route' => ['/missing', 'GET', \Componenta\Http\Router\Exception\RouteNotFoundException::class, 404],
    'wrong method' => ['/', 'POST', MethodNotAllowedException::class, 405],
]);
