<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Router;
use Componenta\Http\Router\Routes;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Benchmark with varying route counts to test scalability
 */
#[Iterations(3)]
#[Warmup(1)]
class ScalabilityBench
{
    private array $routers = [];
    private array $cacheFiles = [];

    public function provideRouteCounts(): \Generator
    {
        yield '100 routes' => ['count' => 100];
        yield '500 routes' => ['count' => 500];
        yield '1000 routes' => ['count' => 1000];
    }

    public function setUpRoutes(array $params): void
    {
        $count = $params['count'];
        
        if (isset($this->routers[$count])) {
            return;
        }

        $routes = new Routes();
        $cacheFile = sys_get_temp_dir() . "/router_scale_bench_{$count}_" . uniqid() . '.php';
        $this->cacheFiles[$count] = $cacheFile;

        // Half static, half dynamic
        $half = (int) ($count / 2);

        for ($i = 0; $i < $half; $i++) {
            $routes->addRoute(RouteRecord::get("static.$i", "/static/path/$i", "Controller@static$i"));
        }

        for ($i = 0; $i < $half; $i++) {
            $routes->addRoute(RouteRecord::get("dynamic.$i", "/dynamic/path/$i/[id:\d+]", "Controller@dynamic$i"));
        }

        (new RouteCacheGenerator())->generate($routes, $cacheFile);
        $compiled = CompiledRoutes::fromCache($cacheFile);

        $this->routers[$count] = [
            'dev' => new Router($routes, $routes, $routes),
            'prod' => new Router($compiled, $compiled, $compiled),
            'lastStatic' => $half - 1,
            'lastDynamic' => $half - 1,
        ];
    }

    public function __destruct()
    {
        foreach ($this->cacheFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    // ============ STATIC FIRST (BEST CASE) ============

    #[Revs(500)]
    #[Groups(['scale', 'static', 'best', 'dev'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchStaticFirstDev(array $params): void
    {
        $this->routers[$params['count']]['dev']->match('/static/path/0', 'GET');
    }

    #[Revs(500)]
    #[Groups(['scale', 'static', 'best', 'prod'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchStaticFirstProd(array $params): void
    {
        $this->routers[$params['count']]['prod']->match('/static/path/0', 'GET');
    }

    // ============ STATIC LAST (WORST CASE FOR DEV) ============

    #[Revs(500)]
    #[Groups(['scale', 'static', 'worst', 'dev'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchStaticLastDev(array $params): void
    {
        $last = $this->routers[$params['count']]['lastStatic'];
        $this->routers[$params['count']]['dev']->match("/static/path/$last", 'GET');
    }

    #[Revs(500)]
    #[Groups(['scale', 'static', 'worst', 'prod'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchStaticLastProd(array $params): void
    {
        $last = $this->routers[$params['count']]['lastStatic'];
        $this->routers[$params['count']]['prod']->match("/static/path/$last", 'GET');
    }

    // ============ DYNAMIC FIRST ============

    #[Revs(500)]
    #[Groups(['scale', 'dynamic', 'best', 'dev'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchDynamicFirstDev(array $params): void
    {
        $this->routers[$params['count']]['dev']->match('/dynamic/path/0/999', 'GET');
    }

    #[Revs(500)]
    #[Groups(['scale', 'dynamic', 'best', 'prod'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchDynamicFirstProd(array $params): void
    {
        $this->routers[$params['count']]['prod']->match('/dynamic/path/0/999', 'GET');
    }

    // ============ DYNAMIC LAST (WORST CASE) ============

    #[Revs(500)]
    #[Groups(['scale', 'dynamic', 'worst', 'dev'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchDynamicLastDev(array $params): void
    {
        $last = $this->routers[$params['count']]['lastDynamic'];
        $this->routers[$params['count']]['dev']->match("/dynamic/path/$last/999", 'GET');
    }

    #[Revs(500)]
    #[Groups(['scale', 'dynamic', 'worst', 'prod'])]
    #[ParamProviders('provideRouteCounts')]
    #[BeforeMethods('setUpRoutes')]
    public function benchDynamicLastProd(array $params): void
    {
        $last = $this->routers[$params['count']]['lastDynamic'];
        $this->routers[$params['count']]['prod']->match("/dynamic/path/$last/999", 'GET');
    }
}
