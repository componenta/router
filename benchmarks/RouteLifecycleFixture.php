<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

final class RouteLifecycleFixture
{
    public static function routes(int $count, string $layout): Routes
    {
        $routes = new Routes();
        for ($i = 0, $half = intdiv($count, 2); $i < $half; ++$i) {
            $routes->addRoute(RouteRecord::get('s' . $i, '/static/' . $i, 'StaticHandler'));
            $path = match ($layout) {
                'wide' => '/p' . $i . '/' . $i . '/{id}',
                'shared' => '/items/' . $i . '/{id}',
                'broad' => '/items/{id}/' . $i,
            };
            $routes->addRoute(new RouteRecord('d' . $i, $path, 'DynamicHandler', ['GET', 'POST'], defaults: ['scope' => 'read']));
        }
        return $routes;
    }

    public static function uri(int $i, string $layout): string
    {
        return match ($layout) {
            'wide' => '/p' . $i . '/' . $i . '/42',
            'shared' => '/items/' . $i . '/42',
            'broad' => '/items/42/' . $i,
        };
    }

    private function __construct() {}
}
