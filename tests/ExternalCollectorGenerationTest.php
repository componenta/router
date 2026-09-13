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
        ->and(CompiledRoutes::fromArray([])->generate($collector, 'image', ['id' => 42]))->toBe($expected);
})->with([
    'static suffix' => ['/images/{id}_thumb.jpg', '/images/42_thumb.jpg'],
    'literal colon' => ['/images/{id}/:preview', '/images/42/:preview'],
]);
