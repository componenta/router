<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Exception\RoutePatternException;
use Componenta\Http\Router\Internal\PreparedMap;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;

it('rejects declarations that could replace a route parameter capture', function (string $path, array $tokens, string $uri): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', $path, 'H', tokens: $tokens));
    $message = 'Duplicate PCRE capture for route parameter: value';

    expect(fn () => (new Compiler())->compile($path, $tokens))->toThrow(RoutePatternException::class, $message)
        ->and(fn () => $source->match($source, $uri, 'GET'))->toThrow(RoutePatternException::class, $message)
        ->and(fn () => (new RouteCacheGenerator())->compile($source))->toThrow(RoutePatternException::class, $message)
        ->and(fn () => $source->generate($source, 'item', ['value' => 'ab', 'id' => 42]))->toThrow(RoutePatternException::class, $message);
})->with([
    'same name, Python syntax' => ['/items/{value}', ['value' => '(?J)(?P<value>a)b'], '/items/ab'],
    'same name, angle syntax' => ['/items/{value}', ['value' => '(?J)(?<value>a)b'], '/items/ab'],
    'same name, apostrophe syntax' => ['/items/{value}', ['value' => "(?J)(?'value'a)b"], '/items/ab'],
    'other parameter name' => ['/items/{value}/{id}', ['id' => '(?J)(?<value>\d+)'], '/items/ab/42'],
    'square template' => ['/items/[value]', ['value' => '(?J)(?<value>a)b'], '/items/ab'],
    'combined options' => ['/items/<value>', ['value' => '(?iJ)(?<value>a)b'], '/items/ab'],
    'scoped options' => ['/items/:value', ['value' => '(?J:(?<value>a)b)'], '/items/ab'],
    'curly inline' => ['/items/{value:(?J)(?<value>a)b}', [], '/items/ab'],
    'square inline' => ['/items/[value:(?J)(?<value>a)b]', [], '/items/ab'],
    'angle inline' => ['/items/<value:(?J)(?<value>a)b>', [], '/items/ab'],
    'colon inline' => ['/items/:value((?J)(?<value>a)b)', [], '/items/ab'],
]);

it('preserves unrelated captures and literal text that resembles declarations', function (string $token, string $value): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', '/items/{value}', 'H', tokens: ['value' => $token]));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $prepared] as $routes) {
        expect($routes->match($routes, '/items/' . $value, 'GET')->parameters)->toBe(['value' => $value]);
    }
})->with([
    ['(?J)(?<other>a)b', 'ab'],
    ['(?J)(?<other>a)(?<other>b)', 'ab'],
    ['(?J)(?|(?<other>a)|(?<other>b))', 'a'],
    ['(?J)\Q(?<value>\E', '(?<value>'],
    ['(?J)[(?<value>]+', 'value'],
    ['(?J)[\Q(?<value>\E]+', 'value'],
    ['(?J)[a-z]+(*MARK:(?<value>)', 'abc'],
    ["(?J)(?C'(?<value>')a", 'a'],
    ['(?J)(?i:(?<other>a))', 'A'],
]);

it('rejects an older prepared definition containing an ambiguous capture', function (): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', '/items/{value}', 'H', tokens: ['value' => 'ab']));
    $map = (new RouteCacheGenerator())->compile($source);
    $map['routeData']['item']['tokens']['value'] = '(?J)(?<value>a)b';
    $map['routeData']['item']['record']['tokens']['value'] = '(?J)(?<value>a)b';
    $map['routeData']['item']['regex'] = '#\A(?:/items/(?P<value>(?J)(?<value>a)b))\z#';
    expect(CompiledRoutes::tryFromArray(PreparedMap::seal($map)))->toBeNull();
});

it('checks captures in a separate URL generation syntax', function (): void {
    $source = (new Routes(new Compiler(new SquareBracketSyntax()), new CurlySyntax()))
        ->addRoute(RouteRecord::get('item', '/items/{value:(?J)(?<value>a)b}', 'H'));
    $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator($source->compiler))->compile($source));
    foreach ([$source, $prepared] as $routes) {
        expect(fn () => $routes->generate($routes, 'item', ['value' => 'ab']))
            ->toThrow(RoutePatternException::class, 'Duplicate PCRE capture for route parameter: value');
    }
});
