<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Internal\PreparedMap;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;

/** Isolate prepared-map validation from PHP artifact parsing and source compilation. */
#[Groups(['lifecycle', 'fingerprint'])]
#[Iterations(7)]
#[Revs(1)]
final class PreparedFingerprintBench
{
    private array $map;

    public function provideMaps(): iterable
    {
        foreach ([1000, 10000] as $count) {
            foreach (['plain', 'sparse', 'dense'] as $layout) {
                yield $count . '-' . $layout => ['count' => $count, 'layout' => $layout];
            }
        }
    }

    public function setUp(array $params): void
    {
        $source = new Routes();
        for ($i = 0; $i < $params['count']; ++$i) {
            $defaults = ['scope' => 'read'];
            if ($params['layout'] === 'dense' || ($params['layout'] === 'sparse' && $i === 0)) {
                $defaults['factor'] = 1.23456789;
            }
            $source->addRoute(new RouteRecord('item.' . $i, '/items/{id}/' . $i, 'H', ['GET', 'POST'], defaults: $defaults));
        }
        $this->map = (new RouteCacheGenerator())->compile($source);
    }

    #[ParamProviders('provideMaps')]
    #[BeforeMethods('setUp')]
    public function benchFingerprint(array $params): void
    {
        if (!PreparedMap::valid($this->map)) { throw new \LogicException('Invalid benchmark map.'); }
    }

    #[ParamProviders('provideMaps')]
    #[BeforeMethods('setUp')]
    public function benchLoadFromArray(array $params): void
    {
        CompiledRoutes::fromArray($this->map);
    }
}
