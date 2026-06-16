<?php

declare(strict_types=1);

use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Syntax\CurlySyntax;

// --- Inline defaults ---

it('preserves inline defaults when no explicit defaults passed', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/posts/{page:\d+=1}');

    expect($result->defaults)->toBe(['page' => '1']);
});

it('merges explicit defaults over inline defaults', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/posts/{page:\d+=1}', defaults: ['page' => '5']);

    expect($result->defaults)->toBe(['page' => '5']);
});

// --- Token merging ---

it('uses default pattern for known parameter names', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id}');

    // 'id' has default pattern '\d+' from CompilerInterface::DEFAULT_PATTERNS
    expect($result->tokens['id'])->toBe('\d+');
});

it('inline constraint overrides default pattern', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id:[a-z]+}');

    expect($result->tokens['id'])->toBe('[a-z]+');
});

it('explicit tokens override inline constraint', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id:[a-z]+}', tokens: ['id' => '\w+']);

    expect($result->tokens['id'])->toBe('\w+');
});

// --- Parameters classification ---

it('separates required and optional parameters', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id}/{page?}');

    expect($result->parameters)->toBe(['id' => null])
        ->and($result->optionalParameters)->toBe(['page' => null]);
});

it('reports parameter names for both required and optional', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id}/{page?}');

    expect($result->parameterNames())->toBe(['id', 'page']);
});

it('detects parametrized patterns', function () {
    $compiler = new Compiler(new CurlySyntax());

    expect($compiler->isParametrized('/users/{id}'))->toBeTrue()
        ->and($compiler->isParametrized('/users/list'))->toBeFalse();
});

// --- hasParameters ---

it('reports no parameters for static routes', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/list');

    expect($result->hasParameters())->toBeFalse();
});

it('reports parameters for optional-only routes', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id?}');

    expect($result->hasParameters())->toBeTrue();
});

// --- Regex output ---

it('compiles pattern to regex that matches expected urls', function () {
    $compiler = new Compiler(new CurlySyntax());

    $result = $compiler->compile('/users/{id}/{page?}', tokens: ['id' => '\d+']);
    $regex = '#^' . $result->regex . '$#';

    expect(preg_match($regex, '/users/42', $m))->toBe(1)
        ->and($m['id'])->toBe('42')
        ->and(preg_match($regex, '/users/42/3', $m))->toBe(1)
        ->and($m['page'])->toBe('3')
        ->and(preg_match($regex, '/users'))->toBe(0)
        ->and(preg_match($regex, '/users/abc'))->toBe(0);
});

// --- Default constructor (CompositeSyntax) ---

it('compiles with default CompositeSyntax for all syntax styles', function (string $pattern) {
    $compiler = new Compiler();

    $result = $compiler->compile($pattern);

    expect($result->hasParameters())->toBeTrue()
        ->and($result->parameterNames())->toBe(['id']);
})->with([
    'curly' => ['/users/{id}'],
    'colon' => ['/users/:id'],
    'angle' => ['/users/<id>'],
    'square' => ['/users/[id]'],
]);
