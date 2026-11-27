<?php
declare(strict_types=1);
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DIConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;

it('uses an explicit cache filename and falls back to the source when optimization is disabled', function (bool $enabled): void {
    $root = sys_get_temp_dir() . '/route_factory_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/source.cache.php', '<?php $routes->addRoute(\\Componenta\\Http\\Router\\RouteRecord::get("source", "/source", "SourceHandler"));');
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('built', '/built', 'BuiltHandler'));
    (new RouteCacheGenerator())->generate($routes, $root . '/optimized.php');
    try {
        $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => 'production']),
            new \Componenta\Http\Router\ConfigProvider(), static fn (): array => [
                ConfigKey::ROUTES_FILE => 'source.cache.php',
                ConfigKey::ROUTES_CACHE_FILE => 'optimized.php',
                ConfigKey::COMPILED_PIPELINE => $enabled,
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [PathResolverInterface::class => new PathResolver($root)]],
            ]);
        $locator = (new ContainerFactory())->create($composition->config, $composition->dependencies)->get(RouteLocatorInterface::class);
        expect(array_keys($locator->getRoutes()->toArray()))->toBe([$enabled ? 'built' : 'source']);
    } finally {
        foreach (glob($root . '/*') as $file) { unlink($file); }
        rmdir($root);
    }
})->with([true, false]);

it('preserves the lifetime of source handlers when a cache cannot be used', function (string $mode, bool $cacheFile): void {
    $root = sys_get_temp_dir() . '/route_lifetime_' . bin2hex(random_bytes(8));
    mkdir($root);
    file_put_contents($root . '/routes.php', <<<'PHP'
<?php
$counter = 0;
$routes->addRoute(\Componenta\Http\Router\RouteRecord::get('counter', '/counter',
    static function () use (&$counter): int { return ++$counter; }
));
PHP);
    if ($cacheFile) {
        file_put_contents($root . '/optimized.php', '<?php return null;');
    }

    try {
        $composition = (new ConfigFactory())->create(new Environment(['APP_ENV' => $mode]),
            new \Componenta\Http\Router\ConfigProvider(), static fn (): array => [
                ConfigKey::ROUTES_FILE => 'routes.php',
                ConfigKey::ROUTES_CACHE_FILE => 'optimized.php',
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [PathResolverInterface::class => new PathResolver($root)]],
            ]);
        $locator = (new ContainerFactory())->create($composition->config, $composition->dependencies)->get(RouteLocatorInterface::class);

        foreach ([[], [], ['unused' => true], []] as $context) {
            $routes = $locator->getRoutes($context);
            $handler = $routes->match($routes, '/counter', 'GET')->handler->value;
            expect($handler())->toBe(1)->and($handler())->toBe(2);
        }
    } finally {
        foreach (glob($root . '/*') as $file) { unlink($file); }
        rmdir($root);
    }
})->with(['development', 'production'])->with(['missing cache' => false, 'unexportable routes' => true]);


it('protects a reserved artifact behind a directory alias before its descendants exist', function (): void {
    $root = sys_get_temp_dir() . '/route_future_artifact_' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/real');
    if (!@symlink($root . '/real', $root . '/alias')) {
        rmdir($root . '/real');
        rmdir($root);
        test()->markTestSkipped('Symbolic links unavailable.');
    }

    $container = static function (string $file) use ($root): \Componenta\Config\ContainerValue {
        $composition = (new ConfigFactory())->create(new Environment([]),
            static fn (): array => [
                ConfigKey::ROUTES_FILE => 'routes.php',
                ConfigKey::ROUTES_CACHE_FILE => $file,
                DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [
                    PathResolverInterface::class => new PathResolver($root),
                ]],
            ]);
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    };

    try {
        $reserved = $root . '/real/not-yet-created/nested/classes.php';
        $alias = 'alias/not-yet-created/nested/classes.php';
        expect(is_dir(dirname($reserved)))->toBeFalse();
        expect(fn () => \Componenta\Http\Router\Factory\RouteLocatorFactory::cacheFile($container($alias), [$reserved]))
            ->toThrow(InvalidArgumentException::class, 'another application artifact');
        $sibling = 'alias/not-yet-created/nested/routes.php';
        expect(\Componenta\Http\Router\Factory\RouteLocatorFactory::cacheFile($container($sibling), [$reserved]))
            ->toBe($root . '/' . $sibling);

        // A dangling directory alias is ambiguous until another builder
        // creates its target. Do not accept a potentially conflicting path.
        symlink($root . '/future', $root . '/dangling');
        expect(fn () => \Componenta\Http\Router\Factory\RouteLocatorFactory::cacheFile($container('dangling/map.php')))
            ->toThrow(InvalidArgumentException::class, 'Cannot resolve route artifact path');
    } finally {
        if (is_link($root . '/dangling')) { unlink($root . '/dangling'); }
        unlink($root . '/alias');
        rmdir($root . '/real');
        rmdir($root);
    }
});


it('preserves literal backslashes in resolved POSIX artifact paths without weakening alias protection', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') {
        test()->markTestSkipped('POSIX filename semantics required.');
    }
    $root = sys_get_temp_dir() . '/route_posix_' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/a');
    mkdir($root . '/a/b');
    mkdir($root . '/a\b');
    $alias = $root . '/alias';
    $sourceFile = $root . '/a\b/routes.php';
    $otherFile = $root . '/a/b/routes.php';
    try {
        if (!@symlink($root . '/a\b', $alias)) {
            test()->markTestSkipped('Symbolic links unavailable.');
        }
        file_put_contents($sourceFile, 'source');
        file_put_contents($otherFile, 'different file');

        $container = static function (string $source, string $cache) use ($root): \Componenta\Config\ContainerValue {
            $composition = (new ConfigFactory())->create(new Environment([]),
                static fn (): array => [
                    ConfigKey::ROUTES_FILE => $source,
                    ConfigKey::ROUTES_CACHE_FILE => $cache,
                    DIConfigKey::DEPENDENCIES => [DIConfigKey::SERVICES => [
                        PathResolverInterface::class => new PathResolver($root),
                    ]],
                ]);
            return (new ContainerFactory())->create($composition->config, $composition->dependencies);
        };

        $factory = \Componenta\Http\Router\Factory\RouteLocatorFactory::class;
        expect($factory::cacheFile($container('alias/routes.php', 'a/b/routes.php')))->toBe($otherFile)
            ->and($factory::cacheFile($container('source.php', 'a/b/routes.php'), [$sourceFile]))->toBe($otherFile);

        $future = $root . '/a/b/future/nested/map.php';
        $reservedFuture = $root . '/a\b/future/nested/map.php';
        expect($factory::cacheFile($container('alias/future/nested/map.php', 'a/b/future/nested/map.php')))->toBe($future)
            ->and($factory::cacheFile($container('source.php', 'a/b/future/nested/map.php'), [$reservedFuture]))->toBe($future);

        expect(fn () => $factory::cacheFile($container('alias/routes.php', 'alias/routes.php')))
            ->toThrow(InvalidArgumentException::class, 'must not overwrite its source file')
            ->and(fn () => $factory::cacheFile($container('source.php', 'alias/routes.php'), [$sourceFile]))
            ->toThrow(InvalidArgumentException::class, 'another application artifact')
            ->and(fn () => $factory::cacheFile($container('source.php', 'alias/future/nested/map.php'), [$reservedFuture]))
            ->toThrow(InvalidArgumentException::class, 'another application artifact');
    } finally {
        if (is_link($alias)) { unlink($alias); }
        if (is_file($sourceFile)) { unlink($sourceFile); }
        if (is_file($otherFile)) { unlink($otherFile); }
        rmdir($root . '/a\b');
        rmdir($root . '/a/b');
        rmdir($root . '/a');
        rmdir($root);
    }
});
