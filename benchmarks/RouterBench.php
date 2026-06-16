<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Router;
use Componenta\Http\Router\Routes;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Warmup(2)]
class RouterBench
{
    private static bool $initialized = false;
    private static Routes $routes;
    private static CompiledRoutes $compiledRoutes;
    private static Router $devRouter;
    private static Router $prodRouter;
    private static string $cacheFile;

    public function setUp(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$cacheFile = sys_get_temp_dir() . '/router_bench_cache.php';

        self::$routes = new Routes();

        // Static routes (100)
        for ($i = 0; $i < 100; $i++) {
            self::$routes->addRoute(RouteRecord::get("static.$i", "/static/route/$i", "StaticController@action$i"));
        }

        // Dynamic routes with single parameter (100)
        for ($i = 0; $i < 100; $i++) {
            self::$routes->addRoute(RouteRecord::get("dynamic.$i", "/dynamic/$i/[id:\d+]", "DynamicController@action$i"));
        }

        // Nested dynamic routes with multiple parameters (50)
        for ($i = 0; $i < 50; $i++) {
            self::$routes->addRoute(RouteRecord::get(
                "nested.$i",
                "/api/v$i/users/[userId:\d+]/posts/[postId:\d+]",
                "NestedController@action$i"
            ));
        }

        // Optional parameter routes (50)
        for ($i = 0; $i < 50; $i++) {
            self::$routes->addRoute(RouteRecord::get("optional.$i", '/optional/' . $i . '[?page:\d+]', "OptionalController@action$i"));
        }

        // Generate cache once
        new RouteCacheGenerator()->generate(self::$routes, self::$cacheFile);
        self::$compiledRoutes = CompiledRoutes::fromCache(self::$cacheFile);

        self::$devRouter = new Router(self::$routes, self::$routes, self::$routes);
        self::$prodRouter = new Router(self::$compiledRoutes, self::$compiledRoutes, self::$compiledRoutes);

        self::$initialized = true;
    }

    // ============ STATIC ROUTE MATCHING ============

    #[Revs(1000)]
    #[Groups(['match', 'static', 'dev'])]
    public function benchMatchStaticFirstDev(): void
    {
        self::$devRouter->match('/static/route/0', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'static', 'prod'])]
    public function benchMatchStaticFirstProd(): void
    {
        self::$prodRouter->match('/static/route/0', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'static', 'dev'])]
    public function benchMatchStaticLastDev(): void
    {
        self::$devRouter->match('/static/route/99', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'static', 'prod'])]
    public function benchMatchStaticLastProd(): void
    {
        self::$prodRouter->match('/static/route/99', 'GET');
    }

    // ============ DYNAMIC ROUTE MATCHING ============

    #[Revs(1000)]
    #[Groups(['match', 'dynamic', 'dev'])]
    public function benchMatchDynamicFirstDev(): void
    {
        self::$devRouter->match('/dynamic/0/123', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'dynamic', 'prod'])]
    public function benchMatchDynamicFirstProd(): void
    {
        self::$prodRouter->match('/dynamic/0/123', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'dynamic', 'dev'])]
    public function benchMatchDynamicLastDev(): void
    {
        self::$devRouter->match('/dynamic/99/123', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'dynamic', 'prod'])]
    public function benchMatchDynamicLastProd(): void
    {
        self::$prodRouter->match('/dynamic/99/123', 'GET');
    }

    // ============ NESTED DYNAMIC MATCHING ============

    #[Revs(1000)]
    #[Groups(['match', 'nested', 'dev'])]
    public function benchMatchNestedDev(): void
    {
        self::$devRouter->match('/api/v25/users/42/posts/15', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'nested', 'prod'])]
    public function benchMatchNestedProd(): void
    {
        self::$prodRouter->match('/api/v25/users/42/posts/15', 'GET');
    }

    // ============ OPTIONAL PARAMETER MATCHING ============

    #[Revs(1000)]
    #[Groups(['match', 'optional', 'dev'])]
    public function benchMatchOptionalWithParamDev(): void
    {
        self::$devRouter->match('/optional/25/5', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'optional', 'prod'])]
    public function benchMatchOptionalWithParamProd(): void
    {
        self::$prodRouter->match('/optional/25/5', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'optional', 'dev'])]
    public function benchMatchOptionalWithoutParamDev(): void
    {
        self::$devRouter->match('/optional/25', 'GET');
    }

    #[Revs(1000)]
    #[Groups(['match', 'optional', 'prod'])]
    public function benchMatchOptionalWithoutParamProd(): void
    {
        self::$prodRouter->match('/optional/25', 'GET');
    }

    // ============ NOT FOUND (WORST CASE) ============

    #[Revs(1000)]
    #[Groups(['match', 'notfound', 'dev'])]
    public function benchMatchNotFoundDev(): void
    {
        try {
            self::$devRouter->match('/this/route/does/not/exist/999', 'GET');
        } catch (RouteNotFoundException) {
            // Expected
        }
    }

    #[Revs(1000)]
    #[Groups(['match', 'notfound', 'prod'])]
    public function benchMatchNotFoundProd(): void
    {
        try {
            self::$prodRouter->match('/this/route/does/not/exist/999', 'GET');
        } catch (RouteNotFoundException) {
            // Expected
        }
    }

    // ============ URL GENERATION ============

    #[Revs(1000)]
    #[Groups(['generate', 'static', 'dev'])]
    public function benchGenerateStaticDev(): void
    {
        self::$devRouter->generate('static.50');
    }

    #[Revs(1000)]
    #[Groups(['generate', 'static', 'prod'])]
    public function benchGenerateStaticProd(): void
    {
        self::$prodRouter->generate('static.50');
    }

    #[Revs(1000)]
    #[Groups(['generate', 'dynamic', 'dev'])]
    public function benchGenerateDynamicDev(): void
    {
        self::$devRouter->generate('dynamic.50', ['id' => 123]);
    }

    #[Revs(1000)]
    #[Groups(['generate', 'dynamic', 'prod'])]
    public function benchGenerateDynamicProd(): void
    {
        self::$prodRouter->generate('dynamic.50', ['id' => 123]);
    }

    #[Revs(1000)]
    #[Groups(['generate', 'nested', 'dev'])]
    public function benchGenerateNestedDev(): void
    {
        self::$devRouter->generate('nested.25', [
            'userId' => 42,
            'postId' => 15,
        ]);
    }

    #[Revs(1000)]
    #[Groups(['generate', 'nested', 'prod'])]
    public function benchGenerateNestedProd(): void
    {
        self::$prodRouter->generate('nested.25', [
            'userId' => 42,
            'postId' => 15,
        ]);
    }

    #[Revs(1000)]
    #[Groups(['generate', 'optional', 'dev'])]
    public function benchGenerateOptionalWithParamDev(): void
    {
        self::$devRouter->generate('optional.25', ['page' => 5]);
    }

    #[Revs(1000)]
    #[Groups(['generate', 'optional', 'prod'])]
    public function benchGenerateOptionalWithParamProd(): void
    {
        self::$prodRouter->generate('optional.25', ['page' => 5]);
    }

    #[Revs(1000)]
    #[Groups(['generate', 'optional', 'dev'])]
    public function benchGenerateOptionalWithoutParamDev(): void
    {
        self::$devRouter->generate('optional.25');
    }

    #[Revs(1000)]
    #[Groups(['generate', 'optional', 'prod'])]
    public function benchGenerateOptionalWithoutParamProd(): void
    {
        self::$prodRouter->generate('optional.25');
    }
}