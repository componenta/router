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


it('keeps the allowed methods and HTTP error responses independent of caller references', function (): void {
    $method = 'GET';
    $allowed = [&$method, 'HEAD'];
    $error = new MethodNotAllowedException('/items', 'POST', $allowed);
    $message = $error->getMessage();

    $method = 'DELETE';
    $allowed[1] = 'PATCH';

    expect($error->allowedMethods)->toBe(['GET', 'HEAD'])
        ->and($error->allowHeader)->toBe('GET, HEAD')
        ->and($error->getMessage())->toBe($message)
        ->and($message)->toContain('Allowed: GET, HEAD');

    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $request = new \Nyholm\Psr7\ServerRequest('POST', '/items');
    $plain = (new \Componenta\Http\Router\Middleware\RouterExceptionHandler($factory))->handle($error, $request);
    $json = (new \Componenta\Http\Router\Middleware\JsonRouterExceptionHandler($factory))->handle($error, $request);

    expect($plain->getStatusCode())->toBe(405)
        ->and($plain->getHeaderLine('Allow'))->toBe('GET, HEAD')
        ->and($json->getStatusCode())->toBe(405)
        ->and($json->getHeaderLine('Allow'))->toBe('GET, HEAD')
        ->and(json_decode((string) $json->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => 'Method Not Allowed', 'method' => 'POST', 'allowed' => ['GET', 'HEAD'],
        ]);
});
