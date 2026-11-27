<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;

/** Measures the hot path with many simultaneously eligible literal prefixes. */
#[Groups(['lifecycle', 'hot', 'overlapping-prefixes'])]
#[Iterations(7)]
#[Revs(100)]
final class OverlappingPrefixBench
{
    private Routes|CompiledRoutes $routes;
    private string $uri;

    public function provideCases(): iterable
    {
        foreach ([4, 64, 256, 512] as $count) {
            foreach (['source', 'prepared'] as $mode) {
                yield $count . '-' . $mode => ['count' => $count, 'mode' => $mode];
            }
        }
    }

    public function setUp(array $params): void
    {
        $source = new Routes();
        $source->addRoute(new RouteRecord('reject', '/a/{any}', 'Reject', ['GET', 'POST'], tokens: ['any' => 'never']));
        $prefix = '/a';
        for ($i = 2; $i <= $params['count']; ++$i) {
            $prefix .= '/a';
            $source->addRoute(new RouteRecord('route.' . $i, $prefix . '/{any}', 'Handler', ['GET', 'POST']));
        }
        $this->uri = $prefix . '/target';
        $this->routes = $params['mode'] === 'source'
            ? $source
            : CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
        if ($this->routes->match($this->routes, $this->uri, 'GET')->name !== 'route.2') {
            throw new \LogicException('Unexpected overlapping-prefix benchmark fixture.');
        }
    }

    #[ParamProviders('provideCases')]
    #[BeforeMethods('setUp')]
    public function benchMatch(array $params): void
    {
        $this->routes->match($this->routes, $this->uri, 'GET');
    }

    #[ParamProviders('provideCases')]
    #[BeforeMethods('setUp')]
    public function benchMethodNotAllowed(array $params): void
    {
        try {
            $this->routes->match($this->routes, $this->uri, 'DELETE');
        } catch (MethodNotAllowedException) {
            return;
        }
        throw new \LogicException('Expected method mismatch.');
    }
}
