<?php

declare(strict_types=1);

use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Locator\RouteLocator;
use Componenta\Http\Router\Routes;

it('keeps loader arguments isolated while allowing a trusted file to replace its collection', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'router_source_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php $routes = new \\Componenta\\Http\\Router\\Routes(); $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("custom", $prefix, "Handler"));');
        $routes = (new RouteLocator($file, useCache: false))->getRoutes(['prefix' => '/custom', '__routeFile' => '/missing']);
        expect($routes)->toBeInstanceOf(Routes::class)
            ->and($routes->getRoute('custom')->path)->toBe('/custom');
    } finally { unlink($file); }
});

it('discards unexpected output from an invalid standalone cache without polluting the response', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'router_map_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php echo "unexpected"; return [];');
        ob_start();
        try {
            expect(CompiledRoutes::tryFromCache($file))->toBeNull();
            expect(ob_get_contents())->toBe('');
        } finally { ob_end_clean(); }
    } finally { unlink($file); }
});
