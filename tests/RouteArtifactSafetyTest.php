<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\VarExport\Exception\ExceptionInterface;

it('does not replace a standalone map with a closure of different lifetime', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'route_stateful_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, 'previous map');
        $routes = new Routes();
        $count = 0;
        $routes->addRoute(RouteRecord::get('counter', '/counter', static function () use (&$count): int { return ++$count; }));
        expect(fn () => (new RouteCacheGenerator())->generate($routes, $file))->toThrow(ExceptionInterface::class);
        expect(file_get_contents($file))->toBe('previous map');
    } finally { unlink($file); }
});
