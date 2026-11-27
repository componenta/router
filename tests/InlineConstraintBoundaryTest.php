<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('retains the complete inline expression and does not detect nested syntax markers', function (string $pattern): void {
    $source = new Routes();
    $source->addRoute(RouteRecord::get('item', $pattern, 'Handler'));
    $compiled = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
    foreach ([$source, $compiled] as $routes) {
        expect($routes->match($routes, '/items/abc', 'GET')->parameters)->toBe(['value' => 'abc'])
            ->and($routes->generate($routes, 'item', ['value' => 'abc']))->toBe('/items/abc')
            ->and(fn () => $routes->match($routes, '/items/xyz', 'GET'))->toThrow(RouteNotFoundException::class);
    }
})->with([
    'curly lookahead' => ['/items/{value:(?=abc)[a-z]+}'],
    'square lookahead with named capture' => ['/items/[value:(?=abc)(?<inner>[a-z]+)]'],
    'angle named capture' => ['/items/<value:(?=abc)(?<inner>[a-z]+)>'],
    'colon nested parentheses' => ['/items/:value((?=abc)(?:[a-z]+))'],
]);

it('rejects unfinished placeholders instead of treating them as literal paths', function (string $pattern): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('broken', $pattern, 'Handler'));
    expect(fn () => $routes->match($routes, '/items/abc', 'GET'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new RouteCacheGenerator())->compile($routes))->toThrow(InvalidArgumentException::class);
})->with(['/items/{value:[a-z]+', '/items/[value:(abc)', '/items/<value:(abc)', '/items/:value((abc)']);


it('preserves quoted class boundaries and control escapes in every inline syntax', function (string $token): void {
    $oracle = '#\A(?:' . $token . ')\z#';
    foreach ([
        '/items/{value:' . $token . '}',
        '/items/[value:' . $token . ']',
        '/items/<value:' . $token . '>',
        '/items/:value(' . $token . ')',
    ] as $pattern) {
        $source = (new Routes())->addRoute(RouteRecord::get('item', $pattern, 'Handler'));
        $compiled = $source->compiler->compile($pattern);
        expect($compiled->tokens['value'])->toBe($token);
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));
        foreach (['a', 'b', ']', '[', ']>', '}', '=', ']}', ')', '])', '[]', 'a]b', '^', '-', chr(29), chr(30), chr(28), chr(9), ''] as $value) {
            $matches = preg_match($oracle, $value);
            expect($matches)->not->toBeFalse();
            foreach ([$source, $prepared] as $routes) {
                if ($matches === 1) {
                    expect($routes->match($routes, '/items/' . $value, 'GET')->parameters)
                        ->toBe(['value' => $value]);
                } else {
                    expect(fn () => $routes->match($routes, '/items/' . $value, 'GET'))
                        ->toThrow(RouteNotFoundException::class);
                }
            }
        }
    }
})->with([
    '[\Q]\E]+',
    '[\Q]}=\E]+',
    '[\Q]>\E]+',
    '[\Q])\E]+',
    '[\Q[]\E]+',
    '[a\Q]b\E]+',
    '[\Q\E]]+',
    '[^\Q\E]]+',
    '[\Q\E]a]+',
    '[\E]a]+',
    '[^\E]a]+',
    '[\Q\E^a]+',
    '[\Q\E[:alpha:]\Q]\E]+',
    '[\Q[:alpha:]\E]+',
    '[\Q]\E[:alpha:]]+',
    '[\Q]\E\Q}\E]+',
    '[\Q]\E\d]+',
    '[\Q]\E\]]+',
    '[\Q]\E-]+',
    '[\Q\E\Q]\E]+',
    '[]a]+',
    '[^]a]+',
    '[\]]+',
    '[[:alpha:]\]]+',
    '[\c]]+',
    '[\c}]+',
    '[\c=]+',
    '\c}',
    '\c=',
    '\c]',
    '\c>',
    '\c)',
]);


it('preserves opaque PCRE control verb and string callout payloads in every inline syntax', function (string $token): void {
    $oracle = '#\A(?:/items/(?P<value>' . $token . '))\z#';
    foreach ([
        '/items/{value:' . $token . '}',
        '/items/[value:' . $token . ']',
        '/items/<value:' . $token . '>',
        '/items/:value(' . $token . ')',
    ] as $pattern) {
        $source = (new Routes())->addRoute(RouteRecord::get('item', $pattern, 'H'));
        expect($source->compiler->compile($pattern)->tokens['value'])->toBe($token);
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));

        foreach (['ab', 'a', 'b', 'c', 'abc', 'aa', 'ba', '', ']', '[', 'a/b'] as $value) {
            $uri = '/items/' . $value;
            $matched = preg_match($oracle, $uri, $captures, PREG_UNMATCHED_AS_NULL);
            expect($matched)->not->toBeFalse();
            foreach ([$source, $prepared] as $routes) {
                if ($matched === 1) {
                    expect($routes->match($routes, $uri, 'GET')->parameters)->toBe(['value' => $captures['value']]);
                } else {
                    expect(fn () => $routes->match($routes, $uri, 'GET'))->toThrow(RouteNotFoundException::class);
                }
            }
        }
    }
})->with([
    'a(*MARK:[)b',
    'a(*MARK:{)b',
    'a(*MARK:[}=< >)b',
    'a(*:foo[)b',
    'a(*PRUNE:[)b',
    'a(*SKIP:{)b',
    'a(*THEN:[)b',
    'a(*COMMIT:{)b',
    'a(*MARK:a\)b',
    "a(?C'[')b",
    "a(?C')')b",
    "a(?C'{)[]}')b",
    'a(?C"{[)")b',
    "a(?C'{''[)')b",
    'a(?C{[)})b',
    'a(?C{}}[)})b',
    'a(?C^ [)^)b',
    'a(?C% [)%)b',
    'a(?C$ [)$)b',
    'a(?C^a^^b^)b',
    'a(*MARK:foo[)b|c',
    'a(?C"[}")b|c',
    'a(?C1)b',
    'a(*pla:b)b',
    'a(*napla:b)b',
    'a(?:[b])',
    'a(?=b)b',
    'a(?C)b',
    'a(*MARK:label)b',
]);
