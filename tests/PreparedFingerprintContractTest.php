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


it('keeps the format 16 fingerprint byte-compatible for partially normalized nested maps', function (): void {
    $map = [
        'version' => 16,
        'nested' => [
            'float' => 1.25,
            'negativeZero' => -0.0,
            'plain' => ['key' => [1, 2, '3', null]],
        ],
        'later' => ['nested' => ['value' => INF]],
    ];
    // Recorded using the previous format-16 fingerprint implementation.
    $fingerprint = '4fe6ff084110efa5a5fa61f55bdaa3d4';
    $previous = ini_get('serialize_precision');
    try {
        foreach (['-1', '3', '17'] as $precision) {
            ini_set('serialize_precision', $precision);
            $sealed = \Componenta\Http\Router\Internal\PreparedMap::seal($map);
            expect($sealed['fingerprint'])->toBe($fingerprint)
                ->and(\Componenta\Http\Router\Internal\PreparedMap::valid($sealed))->toBeTrue()
                ->and($sealed['nested']['plain'])->toBe($map['nested']['plain'])
                ->and(pack('E', $sealed['nested']['negativeZero']))->toBe(pack('E', -0.0));

            $changed = $sealed;
            $changed['nested']['plain']['key'][0] = 2;
            expect(\Componenta\Http\Router\Internal\PreparedMap::valid($changed))->toBeFalse();
        }
    } finally {
        ini_set('serialize_precision', (string) $previous);
    }
});

it('preserves sparse special values and rejects reference slots without serializing objects', function (): void {
    $handler = new class {
        public function __serialize(): array { throw new LogicException('Must not serialize runtime objects.'); }
    };
    $source = new Routes();
    for ($i = 0; $i < 100; ++$i) {
        $source->addRoute(new RouteRecord('item.' . $i, '/items/{id}/' . $i, 'H', ['GET', 'POST'],
            defaults: $i === 0 ? ['factor' => 1.25] : ['scope' => 'read']));
    }
    $source->addRoute(RouteRecord::get('opaque', '/opaque', $handler));
    $map = (new RouteCacheGenerator())->compile($source);
    $prepared = CompiledRoutes::fromArray($map);
    expect($prepared->getRoute('opaque')->handler->value)->toBe($handler);
    for ($i = 0; $i < 100; ++$i) {
        foreach (['GET', 'POST'] as $method) {
            $uri = '/items/42/' . $i;
            expect($prepared->match($prepared, $uri, $method)->parameters)
                ->toBe($source->match($source, $uri, $method)->parameters);
        }
    }

    $value = 'reference';
    $changed = $map;
    $changed['routeData']['item.50']['handler'] =& $value;
    expect(\Componenta\Http\Router\Internal\PreparedMap::seal($changed)['fingerprint'])->toBeNull();
});
