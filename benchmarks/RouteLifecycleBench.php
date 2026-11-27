<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Routes;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;

/** Separates collection construction and first match from steady-state requests. */
#[Iterations(7)]
final class RouteLifecycleBench
{
    private Routes $source;
    private CompiledRoutes $cached;
    private string $file;
    private int $last;
    private string $firstUri;
    private string $lastUri;
    private string $deepMiss;
    private RouteCacheGenerator $generator;

    public function provideShapes(): iterable
    {
        foreach ([100, 1000, 10000] as $count) {
            foreach (['wide', 'shared', 'broad'] as $layout) {
                yield $count . '-' . $layout => ['count' => $count, 'layout' => $layout];
            }
        }
    }

    public function provideModes(): iterable
    {
        foreach ($this->provideShapes() as $name => $shape) {
            foreach (['source', 'cached'] as $mode) {
                yield $name . '-' . $mode => $shape + ['mode' => $mode];
            }
        }
    }

    public function setUp(array $params): void
    {
        if (isset($this->file)) { @unlink($this->file); }
        $this->file = tempnam(sys_get_temp_dir(), 'route-lifecycle-')
            ?: throw new \RuntimeException('Cannot create route benchmark fixture.');
        $this->last = intdiv($params['count'], 2) - 1;
        $this->firstUri = RouteLifecycleFixture::uri(0, $params['layout']);
        $this->lastUri = RouteLifecycleFixture::uri($this->last, $params['layout']);
        $this->deepMiss = '/absent/' . str_repeat('a/', 20000) . 'end';
        $this->generator = new RouteCacheGenerator();
        $this->source = RouteLifecycleFixture::routes($params['count'], $params['layout']);
        $this->generator->generate($this->source, $this->file);
        $this->cached = CompiledRoutes::fromCache($this->file);
        foreach ([$this->source, $this->cached] as $routes) {
            $match = $routes->match($routes, $this->lastUri, 'GET');
            if ($match->name !== 'd' . $this->last || $match->parameters !== ['scope' => 'read', 'id' => 42]) {
                throw new \LogicException('Invalid route benchmark fixture.');
            }
        }
    }

    public function __destruct()
    {
        if (isset($this->file)) { @unlink($this->file); }
    }

    private function current(array $params): Routes|CompiledRoutes
    {
        return $params['mode'] === 'source' ? $this->source : $this->cached;
    }

    #[Groups(['lifecycle', 'initialization'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1)]
    public function benchFreshCollectionAndFirstMatch(array $params): void
    {
        $routes = $params['mode'] === 'source'
            ? RouteLifecycleFixture::routes($params['count'], $params['layout'])
            : CompiledRoutes::fromCache($this->file);
        $routes->match($routes, $this->lastUri, 'GET');
    }

    #[Groups(['lifecycle', 'preparation'])]
    #[ParamProviders('provideShapes')]
    #[BeforeMethods('setUp')]
    #[Revs(1)]
    public function benchCompileMap(array $params): void
    {
        $this->generator->compile($this->source);
    }

    #[Groups(['lifecycle', 'preparation'])]
    #[ParamProviders('provideShapes')]
    #[BeforeMethods('setUp')]
    #[Revs(1)]
    public function benchExportMap(array $params): void
    {
        $this->generator->generate($this->source, $this->file);
    }

    #[Groups(['lifecycle', 'initialization'])]
    #[ParamProviders('provideShapes')]
    #[BeforeMethods('setUp')]
    #[Revs(1)]
    public function benchLoadAndValidateMap(array $params): void
    {
        CompiledRoutes::fromCache($this->file);
    }

    #[Groups(['lifecycle', 'hot'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1000)]
    public function benchStatic(array $params): void
    {
        $routes = $this->current($params);
        $routes->match($routes, '/static/' . $this->last, 'GET');
    }

    #[Groups(['lifecycle', 'hot'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1000)]
    public function benchDynamicFirst(array $params): void
    {
        $routes = $this->current($params);
        $routes->match($routes, $this->firstUri, 'GET');
    }

    #[Groups(['lifecycle', 'hot'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1000)]
    public function benchDynamicLast(array $params): void
    {
        $routes = $this->current($params);
        $routes->match($routes, $this->lastUri, 'GET');
    }

    #[Groups(['lifecycle', 'hot'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1000)]
    public function benchNotFound(array $params): void
    {
        $routes = $this->current($params);
        try { $routes->match($routes, '/absent/no', 'GET'); }
        catch (RouteNotFoundException) { return; }
        throw new \LogicException('Expected route miss.');
    }

    #[Groups(['lifecycle', 'hot'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1000)]
    public function benchMethodNotAllowed(array $params): void
    {
        $routes = $this->current($params);
        try { $routes->match($routes, $this->lastUri, 'DELETE'); }
        catch (MethodNotAllowedException) { return; }
        throw new \LogicException('Expected method mismatch.');
    }

    #[Groups(['lifecycle', 'hot'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(1000)]
    public function benchGenerate(array $params): void
    {
        $routes = $this->current($params);
        $routes->generate($routes, 'd' . $this->last, ['id' => 42]);
    }

    #[Groups(['lifecycle', 'hot', 'long-uri'])]
    #[ParamProviders('provideModes')]
    #[BeforeMethods('setUp')]
    #[Revs(100)]
    public function benchDeepNotFound(array $params): void
    {
        $routes = $this->current($params);
        try { $routes->match($routes, $this->deepMiss, 'GET'); }
        catch (RouteNotFoundException) { return; }
        throw new \LogicException('Expected deep route miss.');
    }
}
