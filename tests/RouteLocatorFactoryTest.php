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
