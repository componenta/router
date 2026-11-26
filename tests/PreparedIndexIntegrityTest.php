<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('checks prefix semantics, ordering, and nested record shape at every map entry point', function (): void {
    $routes = new Routes();
    // Captures force the independent-regex strategy.
    $routes->addRoute(RouteRecord::get('alpha', '/alpha/{value}', 'Alpha', tokens: ['value' => '(?:a)+']));
    $routes->addRoute(RouteRecord::get('beta', '/beta/{value}', 'Beta'));
    $map = (new RouteCacheGenerator())->compile($routes);
    expect(CompiledRoutes::tryFromArray($map))->not->toBeNull();
    $invalid = [];
    $wrongPrefix = $map;
    $wrongPrefix['prefixIndex']['GET'] = ['alpha' => [1], 'beta' => [0]];
    $invalid[] = $wrongPrefix;
    $wrongOrder = $map;
    $wrongOrder['dynamicChunks']['GET'] = array_reverse($wrongOrder['dynamicChunks']['GET']);
    $invalid[] = $wrongOrder;
    $nestedHandler = $map;
    $nestedHandler['routeData']['alpha']['record']['handler'] = 'Other';
    $invalid[] = $nestedHandler;
    $missingIndex = $map;
    unset($missingIndex['prefixIndex']);
    $invalid[] = $missingIndex;
    foreach ($invalid as $payload) {
        expect(CompiledRoutes::tryFromArray($payload))->toBeNull()
            ->and(fn () => CompiledRoutes::fromArray($payload))->toThrow(InvalidArgumentException::class);
    }
});
