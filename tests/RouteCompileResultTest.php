<?php

declare(strict_types=1);

use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\RouteCompileResult;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('detaches caller-owned metadata slots without changing nested value identity', function (mixed $value): void {
    $required = 'a';
    $optional = 'b';
    $token = 'c';
    $default = $value;
    $result = new RouteCompileResult(
        regex: 'expression',
        parameters: ['required' => &$required],
        optionalParameters: ['optional' => &$optional],
        tokens: ['token' => &$token],
        defaults: ['default' => &$default],
    );

    $required = $optional = $token = $default = 'changed';

    expect($result->parameters)->toBe(['required' => 'a'])
        ->and($result->optionalParameters)->toBe(['optional' => 'b'])
        ->and($result->tokens)->toBe(['token' => 'c'])
        ->and($result->defaults)->toBe(['default' => $value])
        ->and($result->parameterNames())->toBe(['required', 'optional']);

    $exported = $result->defaults;
    $exported['default'] = 'different';
    expect($result->defaults)->toBe(['default' => $value]);

    $external = $value;
    $compiled = (new Compiler())->compile('/{id?}', defaults: ['id' => &$external]);
    $external = 'changed';
    expect($compiled->defaults)->toBe(['id' => $value]);
})->with([
    'integer' => [1],
    'float' => [1.25],
    'string' => ['first'],
    'null' => [null],
    'false' => [false],
    'object' => [new stdClass()],
    'array' => [['k' => 'v']],
]);

it('retains an immutable snapshot of a custom compiler result in the source index', function (): void {
    $compiler = new class implements CompilerInterface {
        public string $default = 'first';

        public function compile(string $pattern, array $tokens = [], array $defaults = []): RouteCompileResult
        {
            return new RouteCompileResult($pattern, defaults: ['scope' => &$this->default]);
        }

        public function isParametrized(string $pattern): bool
        {
            return false;
        }
    };

    $source = (new Routes($compiler))->addRoute(RouteRecord::get('home', '/', 'H'));
    expect($source->match($source, '/', 'GET')->parameters)->toBe(['scope' => 'first']);
    $compiler->default = 'changed';
    expect($source->match($source, '/', 'GET')->parameters)->toBe(['scope' => 'first']);
});
