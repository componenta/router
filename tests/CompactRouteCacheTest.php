<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves default constraints when loading current and previous compact formats', function (int $version, bool $custom): void {
    $compiler = new Compiler(defaultPatterns: $custom ? ['id' => '[A-Z]+'] : []);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'ItemsHandler'));
    $data = (new RouteCacheGenerator($compiler))->compile($source);
    $data['version'] = $version;
    $file = tempnam(sys_get_temp_dir(), 'route_constraints_');
    $valid = $custom ? 'ABC' : 17;
    $invalid = $custom ? 'abc' : 'ABC';

    try {
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        foreach ([$source, CompiledRoutes::fromArray($data), CompiledRoutes::fromCache($file)] as $routes) {
            expect($routes->generate($routes, 'item', ['id' => $valid]))->toBe('/items/' . $valid)
                ->and($routes->match($routes, '/items/' . $valid, 'GET')->parameters)->toBe(['id' => $valid]);
            expect(fn () => $routes->generate($routes, 'item', ['id' => $invalid]))
                ->toThrow(InvalidArgumentException::class);
        }
    } finally {
        unlink($file);
    }
})->with([
    'previous implicit constraints' => [4, false],
    'previous custom constraints' => [4, true],
    'current implicit constraints' => [RouteCacheGenerator::CACHE_VERSION, false],
    'current custom constraints' => [RouteCacheGenerator::CACHE_VERSION, true],
]);

it('stores compact route references and restores omitted defaults', function () {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('home.compact', '/', 'HomeController'));
    $routes->addRoute(RouteRecord::post('posts.create', '/posts', 'CreatePostController'));
    $routes->addRoute(RouteRecord::get('users.show', '/users/{id}', 'ShowUserController'));

    $data = (new RouteCacheGenerator())->compile($routes);
    $compiler = new Compiler();

    expect($data['version'])->toBe(RouteCacheGenerator::CACHE_VERSION)
        ->and($data['staticRoutes']['GET']['/'])->toBe('home.compact')
        ->and($data['staticRoutes']['POST']['/posts'])->toBe('posts.create')
        ->and($data['routeMap']['GET']['r0'])->toBe('users.show')
        ->and($data['routeData']['home.compact'])->toMatchArray([
            'path' => '/',
            'handler' => 'HomeController',
        ])
        ->and($data['routeData']['posts.create']['methods'])->toBe(['POST'])
        ->and($data['routeData']['users.show'])->not->toHaveKey('tokens')
        ->and($data)->not->toHaveKey('defaultTokens');

    $file = tempnam(sys_get_temp_dir(), 'componenta_routes_');
    file_put_contents($file, '<?php return ' . var_export($data, true) . ';');

    try {
        $compiled = CompiledRoutes::fromCache($file);

        expect($compiled->match($compiled, '/', 'GET')->name)->toBe('home.compact')
            ->and($compiled->getRoute('home.compact')->methods)->toBe(['GET'])
            ->and($compiled->getRoute('home.compact')->tokens)->toBe($routes->getRoute('home.compact')->tokens)
            ->and($compiled->getRoute('home.compact')->middlewares)->toBeNull()
            ->and($compiled->getRoute('posts.create')->methods)->toBe(['POST'])
            ->and($compiled->match($compiled, '/users/42', 'GET')->parameters)->toBe(['id' => 42])
            ->and($compiled->generate($compiled, 'users.show', ['id' => 42]))->toBe('/users/42');
    } finally {
        @unlink($file);
    }
});

it('uses source matching for the previous format with a MARK parameter', function (): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('marked', '/items/{MARK}', 'ItemsHandler'));
    $source->addRoute(RouteRecord::get('other', '/other/{id}', 'OtherHandler'));
    $data = (new RouteCacheGenerator())->compile($source);
    $data['version'] = 4;
    $data['regex'] = ['GET' => '#^(?:/items/(?P<MARK>[^/]+)(*MARK:r0)|/other/(?P<id>\d+)(*MARK:r1))$#J'];
    $data['routeMap'] = ['GET' => ['r0' => 'marked', 'r1' => 'other']];
    unset($data['dynamicChunks'], $data['prefixIndex']);
    $file = tempnam(sys_get_temp_dir(), 'route_previous_');

    try {
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        foreach ([$source, CompiledRoutes::fromArray($data), CompiledRoutes::fromCache($file)] as $routes) {
            expect($routes->match($routes, '/items/17', 'GET')->parameters)->toBe(['MARK' => 17])
                ->and($routes->match($routes, '/items/r1', 'GET')->name)->toBe('marked')
                ->and($routes->match($routes, '/other/23', 'GET')->parameters)->toBe(['id' => 23])
                ->and($routes->generate($routes, 'marked', ['MARK' => 17]))->toBe('/items/17');
        }
    } finally {
        unlink($file);
    }
});

it('omits empty top-level cache sections and loads an empty cache', function () {
    $generator = new RouteCacheGenerator();
    $routes = new Routes();
    $cacheFile = tempnam(sys_get_temp_dir(), 'componenta_empty_routes_');

    if ($cacheFile === false) {
        throw new RuntimeException('Unable to create a temporary route cache.');
    }

    try {
        $generator->generate($routes, $cacheFile);

        expect(require $cacheFile)->toBe(['version' => RouteCacheGenerator::CACHE_VERSION])
            ->and(CompiledRoutes::fromCache($cacheFile))->toHaveCount(0);

        $routes->addRoute(RouteRecord::get('home', '/', 'HomeController'));

        expect(array_keys($generator->compile($routes)))->toBe([
            'version',
            'staticRoutes',
            'routeData',
        ]);
    } finally {
        @unlink($cacheFile);
    }
});

it('loads route caches generated by the legacy embedded-record format', function () {
    $legacyRoute = [
        'name' => 'legacy.home',
        'path' => '/',
        'handler' => 'LegacyHomeController',
        'methods' => ['GET'],
        'middlewares' => [],
        'tokens' => [],
        'defaults' => [],
        'paramNames' => [],
        'optionalParams' => [],
        'group' => null,
    ];

    $data = [
        'staticRoutes' => ['GET' => ['/' => $legacyRoute]],
        'routeData' => ['legacy.home' => $legacyRoute],
        'regex' => [],
        'routeMap' => [],
    ];

    $file = tempnam(sys_get_temp_dir(), 'componenta_legacy_routes_');
    file_put_contents($file, '<?php return ' . var_export($data, true) . ';');

    try {
        $compiled = CompiledRoutes::fromCache($file);

        expect($compiled->match($compiled, '/', 'GET')->name)->toBe('legacy.home')
            ->and($compiled->getRoute('legacy.home')->name)->toBe('legacy.home')
            ->and($compiled->getRoute('legacy.home')->tokens)->toBe([]);
    } finally {
        @unlink($file);
    }
});
