<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Internal\PreparedMap;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('shares only identical ordered dynamic route sets and preserves method behavior', function (): void {
    $source = new Routes();
    $source->addRoute(new RouteRecord('a', '/a/{id}', 'A', ['GET', 'CUSTOM', 'POST', '0']));
    $source->addRoute(new RouteRecord('b', '/b/{id}', 'B', ['CUSTOM', 'GET', 'POST', '0']));
    $source->addRoute(new RouteRecord('c', '/c/{id}', 'C', ['CUSTOM']));
    $source->addRoute(RouteRecord::post('static', '/s', 'S'));

    $map = (new RouteCacheGenerator())->compile($source);
    expect($map['methodAliases'])->toBe(['POST' => 'GET', 0 => 'GET'])
        ->and(array_keys($map['dynamicIndex']))->toBe(['GET', 'CUSTOM']);
    $cached = CompiledRoutes::fromArray($map);
    $observe = static function ($routes, string $uri, string $method): array {
        try {
            $result = $routes->match($routes, $uri, $method);
            return [$result->name, $result->parameters, $result->route->toArray()];
        } catch (MethodNotAllowedException $error) {
            return [$error::class, $error->getCode(), $error->allowedMethods];
        } catch (RouteNotFoundException $error) {
            return [$error::class, $error->getCode()];
        }
    };
    foreach (['GET', 'POST', 'CUSTOM', '0', 'DELETE'] as $method) {
        foreach (['/a/1', '/b/1', '/c/1', '/s', '/no'] as $uri) {
            expect($observe($cached, $uri, $method))->toBe($observe($source, $uri, $method));
        }
    }
    $invalid = $map;
    $invalid['methodAliases']['POST'] = 'CUSTOM';
    expect(CompiledRoutes::tryFromArray(PreparedMap::seal($invalid)))->toBeNull();
    $invalid = $map;
    $invalid['methodAliases']['POST'] = 'POST';
    expect(CompiledRoutes::tryFromArray(PreparedMap::seal($invalid)))->toBeNull();
});
