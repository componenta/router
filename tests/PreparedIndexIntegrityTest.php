<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Internal\PreparedMap;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('checks prefix semantics, ordering, and nested record shape at every map entry point', function (): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('alpha', '/alpha/{value}', 'Alpha', tokens: ['value' => '(?:a)+']));
    $routes->addRoute(RouteRecord::get('beta', '/beta/{value}', 'Beta'));
    $map = (new RouteCacheGenerator())->compile($routes);
    expect(CompiledRoutes::tryFromArray($map))->not->toBeNull();
    $invalid = [];
    $wrongPrefix = $map;
    $wrongPrefix['dynamicIndex']['GET'] = ['/alpha' => [1], '/beta' => [0]];
    $invalid[] = $wrongPrefix;
    $wrongOrder = $map;
    $wrongOrder['dynamicIndex']['GET'] = array_reverse($wrongOrder['dynamicIndex']['GET'], true);
    $invalid[] = $wrongOrder;
    $nestedHandler = $map;
    $nestedHandler['routeData']['alpha']['record']['handler'] = 'Other';
    $invalid[] = $nestedHandler;
    $missingIndex = $map;
    unset($missingIndex['dynamicIndex']);
    $invalid[] = $missingIndex;
    foreach ($invalid as $payload) {
        $payload = PreparedMap::seal($payload);
        expect(CompiledRoutes::tryFromArray($payload))->toBeNull()
            ->and(fn () => CompiledRoutes::fromArray($payload))->toThrow(InvalidArgumentException::class);
    }
});
