<?php
declare(strict_types=1);

use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves source parameter boundaries when generating from an external collector', function (string $path, string $expected): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('image', $path, 'ImageHandler'));
    $collector = new class($source) implements RouteCollectorInterface {
        public function __construct(private Routes $source) {}
        public function has(string $name): bool { return $this->source->has($name); }
        public function getRoute(string $name): RouteRecord { return $this->source->getRoute($name); }
        public function toArray(): array { return $this->source->toArray(); }
        public function count(): int { return $this->source->count(); }
        public function getIterator(): Traversable { return $this->source->getIterator(); }
    };

    expect($source->generate($collector, 'image', ['id' => 42]))->toBe($expected)
        ->and(CompiledRoutes::fromArray((new \Componenta\Http\Router\Cache\RouteCacheGenerator())->compile(new Routes()))->generate($collector, 'image', ['id' => 42]))->toBe($expected);
})->with([
    'static suffix' => ['/images/{id}_thumb.jpg', '/images/42_thumb.jpg'],
    'literal colon' => ['/images/{id}/:preview', '/images/42/:preview'],
]);


class CachePreparationReadOnlyCollector implements RouteCollectorInterface
{
    public function __construct(protected readonly Routes $source) {}
    public function has(string $name): bool { return $this->source->has($name); }
    public function getRoute(string $name): RouteRecord { return $this->source->getRoute($name); }
    public function toArray(): array { return $this->source->toArray(); }
    public function count(): int { return $this->source->count(); }
    public function getIterator(): Traversable { return $this->source->getIterator(); }
}

it('does not silently discard custom collection matching or generation when preparing routes', function (string $operation): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', '/items/{id}', 'Handler'));
    $collector = match ($operation) {
        'match' => new class($source) extends CachePreparationReadOnlyCollector implements \Componenta\Http\Router\Contract\MatcherInterface {
            public function match(RouteCollectorInterface $routes, string $uri, string $method): \Componenta\Http\Router\MatchResult
            {
                return $this->source->match($this->source, rtrim($uri, '/'), $method);
            }
        },
        'generate' => new class($source) extends CachePreparationReadOnlyCollector implements \Componenta\Http\Router\Contract\GeneratorInterface {
            public function generate(RouteCollectorInterface $routes, string $name, array $parameters = []): string
            {
                return $this->source->generate($this->source, $name, $parameters + ['id' => 7]);
            }
        },
    };
    $matcher = new Routes();
    $router = new \Componenta\Http\Router\Router($collector, $matcher, $matcher);

    if ($operation === 'match') {
        expect($router->match('/items/42/', 'GET')->parameters)->toBe(['id' => 42])
            ->and(fn () => $source->match($source, '/items/42/', 'GET'))
            ->toThrow(\Componenta\Http\Router\Exception\RouteNotFoundException::class);
    } else {
        expect($router->generate('item'))->toBe('/items/7')
            ->and(fn () => $source->generate($source, 'item'))->toThrow(InvalidArgumentException::class);
    }

    $generator = new \Componenta\Http\Router\Cache\RouteCacheGenerator();
    $file = tempnam(sys_get_temp_dir(), 'router_custom_collection_');
    if ($file === false) { throw new RuntimeException('Cannot create route cache fixture.'); }
    try {
        file_put_contents($file, 'previous cache');
        expect(fn () => $generator->compile($collector))
            ->toThrow(\Componenta\Http\Router\Exception\UnsupportedRouteCacheException::class)
            ->and(fn () => $generator->generate($collector, $file))
            ->toThrow(\Componenta\Http\Router\Exception\UnsupportedRouteCacheException::class)
            ->and(file_get_contents($file))->toBe('previous cache');

        // Exporting just the records explicitly remains supported.
        $records = new CachePreparationReadOnlyCollector($source);
        $generator->generate($records, $file);
        $prepared = CompiledRoutes::fromCache($file);
        expect($prepared->match($prepared, '/items/42', 'GET')->parameters)->toBe(['id' => 42])
            ->and($prepared->generate($prepared, 'item', ['id' => 42]))->toBe('/items/42');
    } finally {
        unlink($file);
    }
})->with(['match', 'generate']);
