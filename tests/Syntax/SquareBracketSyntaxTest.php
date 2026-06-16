<?php

declare(strict_types=1);

use Componenta\Http\Router\Syntax\SquareBracketSyntax;

beforeEach(function () {
    $this->syntax = new SquareBracketSyntax();
});

// --- canParse ---

it('detects square bracket parameters', function (string $pattern, bool $expected) {
    expect($this->syntax->canParse($pattern))->toBe($expected);
})->with([
    'required param' => ['/users/[id]', true],
    'optional param' => ['/users/[?id]', true],
    'with constraint' => ['/users/[id:\d+]', true],
    'no params' => ['/users/list', false],
]);

// --- parse ---

it('parses required parameter', function () {
    $result = $this->syntax->parse('/users/[id]');

    expect($result['parameters'])->toBe(['id' => null])
        ->and($result['optionalParameters'])->toBe([]);
});

it('parses optional parameter', function () {
    $result = $this->syntax->parse('/users/[?id]');

    expect($result['optionalParameters'])->toBe(['id' => null]);
});

it('parses optional with constraint', function () {
    $result = $this->syntax->parse('/users/[?id:\d+]');

    expect($result['optionalParameters'])->toBe(['id' => '\d+'])
        ->and($result['tokens'])->toBe(['id' => '\d+']);
});

it('parses optional with default', function () {
    $result = $this->syntax->parse('/users/[?id=5]');

    expect($result['optionalParameters'])->toBe(['id' => null])
        ->and($result['defaults'])->toBe(['id' => '5']);
});

// --- toRegex ---

it('compiles required parameter to regex', function () {
    $regex = $this->syntax->toRegex('/users/[id]', ['id' => '\d+']);

    expect($regex)->toBe('/users/(?P<id>\d+)');
});

it('compiles optional parameter without double slash', function () {
    $regex = $this->syntax->toRegex('/users/[?id]', []);

    expect($regex)->toBe('/users(?:/(?P<id>[^/]+))?');
});

it('matches url without optional parameter', function () {
    $regex = '#^' . $this->syntax->toRegex('/users/[?id]', []) . '$#';

    expect(preg_match($regex, '/users'))->toBe(1);
});

it('matches url with optional parameter present', function () {
    $regex = '#^' . $this->syntax->toRegex('/users/[?id]', ['id' => '\d+']) . '$#';

    expect(preg_match($regex, '/users/123', $m))->toBe(1)
        ->and($m['id'])->toBe('123');
});

// --- normalize ---

it('normalizes optional parameter', function () {
    expect($this->syntax->normalize('/users/[?id:\d+]'))->toBe('/users/[?id]');
});

it('normalizes required parameter', function () {
    expect($this->syntax->normalize('/users/[id:\d+]'))->toBe('/users/[id]');
});

// --- buildPath ---

it('builds path with required parameter', function () {
    $path = $this->syntax->buildPath('/users/[id]', ['id' => 42], [], [], 'test');

    expect($path)->toBe('/users/42');
});

it('builds path with optional parameter absent', function () {
    $path = $this->syntax->buildPath('/users/[?id]', [], [], [], 'test');

    expect($path)->toBe('/users');
});

it('builds path with optional parameter present', function () {
    $path = $this->syntax->buildPath('/users/[?id]', ['id' => 42], [], [], 'test');

    expect($path)->toBe('/users/42');
});

it('throws on missing required parameter', function () {
    expect(fn () => $this->syntax->buildPath('/users/[id]', [], [], [], 'test'))
        ->toThrow(InvalidArgumentException::class);
});
