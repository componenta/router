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


it('rejects invalid and reserved collection variable names before executing the source file', function (string $name): void {
    $file = tempnam(sys_get_temp_dir(), 'router_invalid_variable_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php $probe->executed = true;');
        $probe = (object) ['executed' => false];

        // This public property may be changed after construction.
        $locator = new RouteLocator($file, useCache: false);
        $locator->routesVarName = $name;

        expect(fn () => $locator->getRoutes(['probe' => $probe]))->toThrow(InvalidArgumentException::class)
            ->and($probe->executed)->toBeFalse();
    } finally {
        unlink($file);
    }
})->with([
    'empty' => [''],
    'numeric' => ['123'],
    'leading digit' => ['1routes'],
    'hyphen' => ['my-routes'],
    'space' => ['route name'],
    'dollar sign' => ['$routes'],
    'NUL' => ["a\0b"],
    'this' => ['this'],
    'GLOBALS' => ['GLOBALS'],
    'loader file' => ['__routeFile'],
    'loader context' => ['__routeContext'],
    'loader name' => ['__routeVarName'],
    '_SERVER' => ['_SERVER'],
    '_GET' => ['_GET'],
    '_POST' => ['_POST'],
    '_FILES' => ['_FILES'],
    '_COOKIE' => ['_COOKIE'],
    '_SESSION' => ['_SESSION'],
    '_REQUEST' => ['_REQUEST'],
    '_ENV' => ['_ENV'],
]);

it('binds the collection to valid local PHP variable names rather than the supplied context value', function (string $name): void {
    $file = tempnam(sys_get_temp_dir(), 'router_named_variable_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php $' . $name . '->addRoute(\Componenta\Http\Router\RouteRecord::get("custom", "/custom", "Handler"));');
        $locator = new RouteLocator($file, useCache: false, routesVarName: $name);
        $routes = $locator->getRoutes([$name => null]);
        expect($routes)->toBeInstanceOf(Routes::class)
            ->and($routes->getRoute('custom')->path)->toBe('/custom');
    } finally {
        unlink($file);
    }
})->with(['routes', 'r', 'Routes_1', '_r', '_', 'маршруты', 'é', '路由', 'GLOBALS_1']);

it('does not validate an unused source variable name when loading a standalone compiled map', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'router_cache_variable_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        $source = (new Routes())->addRoute(\Componenta\Http\Router\RouteRecord::get('custom', '/custom', 'Handler'));
        (new \Componenta\Http\Router\Cache\RouteCacheGenerator())->generate($source, $file);
        $routes = (new RouteLocator($file, useCache: true, routesVarName: ''))->getRoutes();
        expect($routes)->toBeInstanceOf(CompiledRoutes::class)
            ->and($routes->getRoute('custom')->path)->toBe('/custom');
    } finally {
        unlink($file);
    }
});


it('injects the collection without modifying caller-owned context references', function (string $name, bool $aliased): void {
    $file = tempnam(sys_get_temp_dir(), 'router_context_reference_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php $' . $name . '->addRoute(\Componenta\Http\Router\RouteRecord::get("custom", "/custom", "Handler")); $other = "local";');
        $external = 'caller';
        $other = 'other';
        $context = [$name => &$external, 'other' => &$other];
        if ($aliased) { $context['other'] =& $external; }
        $locator = new RouteLocator($file, useCache: false, routesVarName: $name);
        $routes = $locator->getRoutes($context);
        expect($routes->getRoute('custom')->path)->toBe('/custom')
            ->and($external)->toBe('caller')
            ->and($other)->toBe('other')
            ->and($context[$name])->toBe('caller')
            ->and($context['other'])->toBe($aliased ? 'caller' : 'other');
    } finally {
        unlink($file);
    }
})->with(['routes', 'r', 'маршруты'])->with([false, true]);


it('falls back when even inspecting the cache path produces a filesystem warning', function (bool $throwWarnings): void {
    $file = 'routerunregistered' . bin2hex(random_bytes(8)) . '://cache.php';
    $source = (new Routes())->addRoute(\Componenta\Http\Router\RouteRecord::get('home', '/', 'Handler'));
    $locator = new \Componenta\Http\Router\Locator\CachedRouteLocator($file, static fn () => new class($source) implements \Componenta\Http\Router\Contract\RouteLocatorInterface {
        public function __construct(private Routes $routes) {}
        public function getRoutes(array $context = []): \Componenta\Http\Router\Contract\RouteCollectorInterface { return $this->routes; }
    });
    $warnings = 0;
    $handler = static function (int $severity, string $message) use (&$warnings, $throwWarnings): bool {
        ++$warnings;
        if ($throwWarnings) { throw new ErrorException($message, 0, $severity); }
        return true;
    };
    set_error_handler($handler);
    try {
        expect(CompiledRoutes::tryFromCache($file))->toBeNull()
            ->and(fn () => CompiledRoutes::fromCache($file))->toThrow(RuntimeException::class, 'Cannot read route cache')
            ->and($locator->getRoutes())->toBe($source)
            ->and($warnings)->toBe(0);
        $current = set_error_handler(static fn () => true);
        restore_error_handler();
        expect($current)->toBe($handler);
    } finally {
        restore_error_handler();
    }
})->with([false, true]);
