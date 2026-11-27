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


it('recognizes the optional suffix only outside PCRE escapes and opaque groups', function (string $text, string $token, bool $optional, ?string $default, string $value): void {
    foreach (['/items/{value:' . $text . '}', '/items/<value:' . $text . '>'] as $pattern) {
        $source = (new Routes())->addRoute(RouteRecord::get('item', $pattern, 'H'));
        $compiled = $source->compiler->compile($pattern);
        expect($compiled->tokens['value'])->toBe($token)
            ->and(array_key_exists('value', $compiled->optionalParameters))->toBe($optional)
            ->and($compiled->defaults['value'] ?? null)->toBe($default);
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));

        foreach ([$source, $prepared] as $routes) {
            expect($routes->match($routes, '/items/' . $value, 'GET')->parameters)->toBe(['value' => $value]);
            if ($optional) {
                expect($routes->match($routes, '/items', 'GET')->parameters)
                    ->toBe($default !== null ? ['value' => $default] : []);
            } else {
                expect(fn () => $routes->match($routes, '/items', 'GET'))->toThrow(RouteNotFoundException::class);
            }
        }
    }
})->with([
    'control question mark' => ['\c?', '\c?', false, null, chr(127)],
    'prefixed control question mark' => ['a\c?', 'a\c?', false, null, 'a' . chr(127)],
    'control escape followed by escaped question mark' => ["\\c\\\\?", "\\c\\\\?", false, null, chr(28) . '?'],
    'optional control question mark' => ['\c??', '\c?', true, null, chr(127)],
    'control question mark with default' => ['\c?=default', '\c?', true, 'default', chr(127)],
    'escaped question mark' => ['\?', '\?', false, null, '?'],
    'optional escaped question mark' => ['\??', '\?', true, null, '?'],
    'class' => ['[?]', '[?]', false, null, '?'],
    'optional class' => ['[?]?', '[?]', true, null, '?'],
    'quoted text' => ['\Q?\E', '\Q?\E', false, null, '?'],
    'optional quoted text' => ['\Q?\E?', '\Q?\E', true, null, '?'],
    'group' => ['(?:a?)?', '(?:a?)', true, null, 'a'],
    'earlier quantifier' => ['a?b?', 'a?b', true, null, 'ab'],
    'no suffix' => ['a?b', 'a?b', false, null, 'b'],
    'callout' => ["a(?C'?')?", "a(?C'?')", true, null, 'a'],
    'mark' => ['a(*MARK:?)?', 'a(*MARK:?)', true, null, 'a'],
    'escaped backslash then optional suffix' => ["a\\\\?", "a\\\\", true, null, "a\\"],
    'control escape then escaped question mark then suffix' => ["\\c\\\\??", "\\c\\\\?", true, null, chr(28) . '?'],
]);

it('rejects old artifacts whose control-escape suffix semantics differ from the current compiler', function (): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', '/items/{value:\c\?}', 'H'));
    $map = (new RouteCacheGenerator())->compile($source);
    expect($map['version'])->toBe(RouteCacheGenerator::CACHE_VERSION);
    $prepared = CompiledRoutes::fromArray($map);
    foreach ([$source, $prepared] as $routes) {
        expect($routes->match($routes, '/items', 'GET')->parameters)->toBe([])
            ->and($routes->match($routes, '/items/' . chr(28), 'GET')->parameters)->toBe(['value' => chr(28)]);
        expect(fn () => $routes->match($routes, '/items/', 'GET'))->toThrow(RouteNotFoundException::class);
    }

    // A format-16 compiler treated the final question mark as escaped.
    $old = $map;
    $old['version'] = 16;
    $old['routeData']['item']['tokens']['value'] = '\c\?';
    unset($old['routeData']['item']['optionalParams']);
    $old['routeData']['item']['regex'] = '#\A(?:/items/(?P<value>\c\?))\z#';
    $old = \Componenta\Http\Router\Internal\PreparedMap::seal($old);
    expect(CompiledRoutes::tryFromArray($old))->toBeNull();

    $file = tempnam(sys_get_temp_dir(), 'router-old-optional-');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, '<?php return ' . var_export($old, true) . ';');
        expect(CompiledRoutes::tryFromCache($file))->toBeNull();
    } finally {
        unlink($file);
    }
});


it('preserves Perl extended character classes in all inline syntaxes', function (string $token): void {
    if (version_compare(explode(' ', PCRE_VERSION, 2)[0], '10.45', '<')) {
        test()->markTestSkipped('Perl extended character classes require PCRE2 10.45.');
    }

    foreach ([
        ['/items/{value:' . $token . '}', false],
        ['/items/[value:' . $token . ']', false],
        ['/items/<value:' . $token . '>', false],
        ['/items/:value(' . $token . ')', false],
        ['/items/{value:' . $token . '?}', true],
        ['/items/[?value:' . $token . ']', true],
        ['/items/<value:' . $token . '?>', true],
        ['/items/:value(' . $token . ')?', true],
    ] as [$pattern, $optional]) {
        $source = (new Routes())->addRoute(RouteRecord::get('item', $pattern, 'H'));
        $compiled = $source->compiler->compile($pattern);
        expect($compiled->tokens['value'])->toBe($token)
            ->and(array_key_exists('value', $compiled->optionalParameters))->toBe($optional);
        $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));

        // Independent expression: do not use the router's compiled regex as
        // the oracle, since source and prepared collections share the lexer.
        $capture = '(?P<value>' . $token . ')';
        $expression = '/items' . ($optional ? '(?:/' . $capture . ')?' : '/' . $capture);
        $oracle = '#\A(?:' . $expression . ')\z#';
        foreach (['/items', '/items/', '/items/a', '/items/b', '/items/c', '/items/z',
            '/items/0', '/items/9', '/items/]', '/items/(', '/items/)', '/items/{',
            '/items/}', '/items/?', '/items/=', '/items/>', '/items/ ', '/items/' . chr(29), '/items/ab'] as $uri) {
            $matched = preg_match($oracle, $uri, $captures, PREG_UNMATCHED_AS_NULL);
            expect($matched)->not->toBeFalse();
            foreach ([$source, $prepared] as $routes) {
                if ($matched === 1) {
                    $value = $captures['value'];
                    $expected = $value === null ? [] : ['value' => in_array($value, ['0', '9'], true) ? (int) $value : $value];
                    expect($routes->match($routes, $uri, 'GET')->parameters)->toBe($expected);
                } else {
                    expect(fn () => $routes->match($routes, $uri, 'GET'))->toThrow(RouteNotFoundException::class);
                }
            }
        }

        foreach (['a', 'b', 'z', '0', '9'] as $value) {
            if (preg_match('#\A(?:' . $token . ')\z#', $value) === 1) {
                foreach ([$source, $prepared] as $routes) {
                    expect($routes->generate($routes, 'item', ['value' => $value]))->toBe('/items/' . $value);
                }
            }
        }
    }
})->with([
    '(?[ ([a-z] - [aeiou]) | [0-9] ])',
    '(?[ [a-z] & ([aeiou] | [b]) ])',
    '(?[ ([0-9] | [=?>)]) - [5] ])',
    '(?[ [ ]a] ])',
    '(?[ [ ^]a] ])',
    '(?[ [^ ]a] ])',
    "(?[ [\t]a] ])",
    '(?[ [\Q ]\E] ])',
    '(?[ [:alpha:] - [a] ])',
    '(?[ \d | \x{5d} ])',
    '(?[ \c] ])',
    '(?[ \] ])',
]);


it('preserves PCRE extended-more option scopes while parsing inline constraints', function (string $token): void {
    foreach ([false, true] as $optional) {
        foreach ([null, 'fallback'] as $default) {
            $suffix = $default === null ? '' : '=' . $default;
            $marker = $optional ? '?' : '';
            foreach ([
                '/items/{value:' . $token . $marker . $suffix . '}',
                '/items/[' . $marker . 'value:' . $token . $suffix . ']',
                '/items/<value:' . $token . $marker . $suffix . '>',
                '/items/:value(' . $token . ')' . $marker . $suffix,
            ] as $pattern) {
                $source = (new Routes())->addRoute(RouteRecord::get('item', $pattern, 'H'));
                $compiled = $source->compiler->compile($pattern);
                $mayOmit = $optional || $default !== null;
                expect($compiled->tokens['value'])->toBe($token)
                    ->and(array_key_exists('value', $compiled->optionalParameters))->toBe($mayOmit)
                    ->and($compiled->defaults['value'] ?? null)->toBe($default);
                $prepared = CompiledRoutes::fromArray((new RouteCacheGenerator())->compile($source));

                $capture = '(?P<value>' . $token . ')';
                $oracle = '#\A(?:/items' . ($mayOmit ? '(?:/' . $capture . ')?' : '/' . $capture) . ')\z#';
                foreach (['/items', '/items/', '/items/a', '/items/b', '/items/]', '/items/(',
                    '/items/=', '/items/?', '/items/}', '/items/ a', '/items/a]', '/items/a('] as $uri) {
                    $matched = preg_match($oracle, $uri, $captures, PREG_UNMATCHED_AS_NULL);
                    expect($matched)->not->toBeFalse();
                    foreach ([$source, $prepared] as $routes) {
                        if ($matched === 1) {
                            $value = $captures['value'] ?? $default;
                            expect($routes->match($routes, $uri, 'GET')->parameters)
                                ->toBe($value === null ? [] : ['value' => $value]);
                        } else {
                            expect(fn () => $routes->match($routes, $uri, 'GET'))->toThrow(RouteNotFoundException::class);
                        }
                    }
                }
            }
        }
    }
})->with([
    '(?xx)[ ]a]',
    '(?xx)[ ](]',
    '(?xx)[ ]=]',
    '(?xx)[ ]?]',
    '(?xx:[ ](])',
    '(?xx:[ ]}])',
    '(?xx)[^ ](]',
    '(?xx)a?[ ](]',
    '(?xx)a?[ ]?]',
    '(?imxx)[ ](]',
    '(?xx)(?i)[ ](]',
    '(?xx)(?x)[ ]a',
    '(?xx)(?-x)[ ]a',
    '(?xx)(?^)[ ]a',
    '(?xx)(?^x)[ ]a',
    '(?xx)(?x:[ ]a)[ ](]',
    '(?xx)(?-x:[ ]a)[ ](]',
    '(?xx)(?:a|(?-x)b)[ ](]',
    '(?xx:[ ]a])[ ]a',
]);

it('rejects version 18 artifacts after the inline option-scope parsing correction', function (): void {
    $source = (new Routes())->addRoute(RouteRecord::get('item', '/items/{value:(?xx)[ ](]}', 'H'));
    $map = (new RouteCacheGenerator())->compile($source);
    expect(CompiledRoutes::fromArray($map)->match(CompiledRoutes::fromArray($map), '/items/(', 'GET')->parameters)
        ->toBe(['value' => '(']);

    $map['version'] = 18;
    $map = \Componenta\Http\Router\Internal\PreparedMap::seal($map);
    expect(CompiledRoutes::tryFromArray($map))->toBeNull();
});
