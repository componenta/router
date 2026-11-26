<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('preserves float defaults when build and runtime serialization precision differ', function (float $value): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/item', 'Handler', defaults: ['value' => $value]));
    $file = tempnam(sys_get_temp_dir(), 'router_float_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    $previous = ini_get('serialize_precision');
    try {
        ini_set('serialize_precision', '3');
        (new RouteCacheGenerator())->generate($source, $file);
        foreach (['-1', '3', '17'] as $precision) {
            ini_set('serialize_precision', $precision);
            $cached = CompiledRoutes::fromCache($file);
            $actual = $cached->match($cached, '/item', 'GET')->parameters['value'];
            expect(is_float($actual))->toBeTrue();
            if (is_nan($value)) {
                expect(is_nan($actual))->toBeTrue();
            } else {
                expect(pack('E', $actual))->toBe(pack('E', $value));
            }
            expect(ini_get('serialize_precision'))->toBe($precision);
        }
    } finally {
        ini_set('serialize_precision', (string) $previous);
        unlink($file);
    }
})->with([1.2345678901234567, 0.0, -0.0, INF, -INF, NAN]);

it('protects in-memory opaque handler identity without invoking serialization hooks', function (): void {
    $handler = new class {
        public function __invoke(): string { return 'ok'; }
        public function __serialize(): array { throw new LogicException('Runtime handlers must not be serialized.'); }
    };
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', '/item', $handler));
    $map = (new RouteCacheGenerator())->compile($source);
    $prepared = CompiledRoutes::fromArray($map);
    expect($prepared->getRoute('item')->handler->value)->toBe($handler);
    $changed = $map;
    $changed['routeData']['item']['handler'] = clone $handler;
    expect(CompiledRoutes::tryFromArray($changed))->toBeNull();
    $changed = $map;
    $changed['fingerprint'] = null;
    expect(CompiledRoutes::tryFromArray($changed))->toBeNull();
});
