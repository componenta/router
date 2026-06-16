<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Factory\RouteLocatorFactory;
use Componenta\Http\Router\Locator\RouteLocator;
use Psr\Container\ContainerInterface;

final readonly class RouterFactoryTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

function routerFactoryConfig(array $configOverrides = []): array
{
    $routesFile = __DIR__ . '/cache/route_locator_factory.routes.php';
    $cacheFile = __DIR__ . '/cache/route_locator_factory.routes.cache.php';

    file_put_contents($routesFile, "<?php\n\ndeclare(strict_types=1);\n");
    file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");

    $config = new Config([
        ConfigKey::ROUTES_FILE => $routesFile,
        ConfigKey::ROUTES_CACHE_FILE => $cacheFile,
        ...$configOverrides,
    ], new Environment(['APP_ENV' => 'production']));

    return [$config, str_replace('\\', '/', $routesFile), str_replace('\\', '/', $cacheFile)];
}

describe('RouteLocatorFactory compiled pipeline flag', function () {
    afterEach(function () {
        @unlink(__DIR__ . '/cache/route_locator_factory.routes.php');
        @unlink(__DIR__ . '/cache/route_locator_factory.routes.cache.php');
    });

    it('uses compiled routes cache by default in production', function () {
        [$config, , $cacheFile] = routerFactoryConfig([]);

        $locator = (new RouteLocatorFactory())(new RouterFactoryTestContainer([
            ConfigKey::CONFIG => $config,
            CompilerInterface::class => new Compiler(),
            PathResolverInterface::class => new PathResolver(__DIR__),
        ]));

        expect($locator)->toBeInstanceOf(RouteLocator::class)
            ->and($locator->filename)->toBe($cacheFile)
            ->and($locator->useCache)->toBeTrue();
    });

    it('falls back to the plain routes file when compiled pipeline is disabled in config', function () {
        [$config, $routesFile] = routerFactoryConfig([ConfigKey::COMPILED_PIPELINE => false]);

        $locator = (new RouteLocatorFactory())(new RouterFactoryTestContainer([
            ConfigKey::CONFIG => $config,
            CompilerInterface::class => new Compiler(),
            PathResolverInterface::class => new PathResolver(__DIR__),
        ]));

        expect($locator)->toBeInstanceOf(RouteLocator::class)
            ->and($locator->filename)->toBe($routesFile)
            ->and($locator->useCache)->toBeFalse();
    });
});
