<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Locator\CachedRouteLocator;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\CompiledRoutes;

it('accepts a generated static index with a route shadowed for one method', function (): void {
    $source = new Routes();
    $source->addRoute(new RouteRecord('original', '/items', 'OriginalHandler', ['GET', 'POST']));
    $source->addRoute(RouteRecord::get('replacement', '/items', 'ReplacementHandler'));
    $file = tempnam(sys_get_temp_dir(), 'route_shadow_');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }

    try {
        (new RouteCacheGenerator())->generate($source, $file);
        $compiled = CompiledRoutes::tryFromCache($file);
        expect($compiled)->toBeInstanceOf(CompiledRoutes::class);
        foreach ([$source, $compiled] as $routes) {
            expect($routes->match($routes, '/items', 'GET')->name)->toBe('replacement')
                ->and($routes->match($routes, '/items', 'POST')->name)->toBe('original')
                ->and($routes->getRoute('original')->handler->value)->toBe('OriginalHandler');
        }
    } finally {
        unlink($file);
    }
});

it('accepts a generated empty map without invoking the source', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'route_empty_');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }
    try {
        (new RouteCacheGenerator())->generate(new Routes(), $file);
        $locator = new CachedRouteLocator($file, static fn () => throw new RuntimeException('Source must not be needed.'));
        expect($locator->getRoutes())->toBeInstanceOf(CompiledRoutes::class)->toHaveCount(0);
    } finally {
        unlink($file);
    }
});

it('falls back before exposing invalid derived route metadata', function (string $field, array $invalid, bool $global = false): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemsHandler'));
    $data = (new RouteCacheGenerator())->compile($source);
    if ($global) {
        $data[$field] = $invalid;
    } else {
        $data['routeData']['item'][$field] = $invalid;
    }
    $file = tempnam(sys_get_temp_dir(), 'route_metadata_');

    try {
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        $locator = new CachedRouteLocator($file, static fn () => new class($source) implements RouteLocatorInterface {
            public function __construct(private Routes $routes) {}
            public function getRoutes(array $context = []): RouteCollectorInterface { return $this->routes; }
        });
        foreach (range(1, 2) as $_) {
            $routes = $locator->getRoutes();
            expect($routes->match($routes, '/items/17', 'GET')->parameters)->toBe(['id' => 17])
                ->and($routes->generate($routes, 'item', ['id' => 23]))->toBe('/items/23')
                ->and(fn () => $routes->generate($routes, 'item'))->toThrow(InvalidArgumentException::class)
                ->and(fn () => $routes->generate($routes, 'item', ['id' => 'abc']))->toThrow(InvalidArgumentException::class);
        }
        expect($locator->getRoutes())->toBe($source);
    } finally {
        unlink($file);
    }
})->with([
    'capture is an array' => ['paramNames', [['id']]],
    'capture is an integer' => ['paramNames', [17]],
    'capture is empty' => ['paramNames', ['']],
    'capture list has named keys' => ['paramNames', ['id' => 'id']],
    'capture occurs twice' => ['paramNames', ['id', 'id']],
    'token is an array' => ['tokens', ['id' => []]],
    'token has an invalid expression' => ['tokens', ['id' => '[']],
    'token has a numeric name' => ['tokens', [0 => '\d+']],
    'default is an array' => ['defaults', ['extra' => []]],
    'default has a numeric name' => ['defaults', [0 => 17]],
    'optional constraint is an array' => ['optionalParams', ['id' => []]],
    'optional name is numeric' => ['optionalParams', [0 => null]],
    'optional constraint has an invalid expression' => ['optionalParams', ['id' => '[']],
    'global token is an array' => ['defaultTokens', ['id' => []], true],
    'global token has an invalid expression' => ['defaultTokens', ['id' => '['], true],
    'global token has a numeric name' => ['defaultTokens', [0 => '\d+'], true],
]);

it('falls back before exposing invalid nested route records', function (string $field, array $invalid): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemsHandler', tokens: ['id' => '\d+']));
    $data = (new RouteCacheGenerator())->compile($source);
    $data['routeData']['item']['record'][$field] = $invalid;
    $file = tempnam(sys_get_temp_dir(), 'route_record_');
    if ($file === false) {
        throw new RuntimeException('Cannot create a route cache fixture.');
    }

    try {
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        $locator = new CachedRouteLocator($file, static fn () => new class($source) implements RouteLocatorInterface {
            public function __construct(private Routes $routes) {}
            public function getRoutes(array $context = []): RouteCollectorInterface { return $this->routes; }
        });

        foreach (range(1, 2) as $_) {
            $routes = $locator->getRoutes();
            expect($routes->getRoute('item')->tokens)->toBe(['id' => '\d+'])
                ->and($routes->getRoute('item')->defaults)->toBe([])
                ->and($routes->match($routes, '/items/17', 'GET')->parameters)->toBe(['id' => 17])
                ->and($routes->generate($routes, 'item', ['id' => 23]))->toBe('/items/23');
        }
    } finally {
        unlink($file);
    }
})->with([
    'token has a non-string value' => ['tokens', ['id' => []]],
    'token has a numeric name' => ['tokens', [0 => '\d+']],
    'token has an invalid expression' => ['tokens', ['id' => '[']],
    'default has a non-scalar value' => ['defaults', ['id' => []]],
    'default has a numeric name' => ['defaults', [0 => 17]],
]);

it('preserves source exceptions after rejecting invalid cached records', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemsHandler'));
    $data = (new RouteCacheGenerator())->compile($source);
    $data['routeData']['item']['record']['tokens'] = ['id' => []];
    $file = tempnam(sys_get_temp_dir(), 'route_record_');
    if ($file === false) {
        throw new RuntimeException('Cannot create a route cache fixture.');
    }
    $error = new RuntimeException('The route source is unavailable.');

    try {
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        $locator = new CachedRouteLocator($file, static fn () => throw $error);
        try {
            $locator->getRoutes();
            test()->fail('An invalid cache must not hide a source failure.');
        } catch (RuntimeException $caught) {
            expect($caught)->toBe($error);
        }
    } finally {
        unlink($file);
    }
});
