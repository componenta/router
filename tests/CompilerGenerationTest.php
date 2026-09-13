<?php

declare(strict_types=1);

use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\RouteCompileResult;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;

it('generates URLs with a compiler decorator implementing only the compiler contract', function (): void {
    $compiler = new class implements CompilerInterface {
        public function compile(string $pattern, array $tokens = [], array $defaults = []): RouteCompileResult {
            return (new Compiler())->compile($pattern, $tokens, $defaults);
        }
        public function isParametrized(string $pattern): bool {
            return (new Compiler())->isParametrized($pattern);
        }
    };
    $routes = new Routes($compiler);
    $routes->addRoute(new RouteRecord('users.show', '/users/[id]', 'handler', ['GET']));

    expect($routes->generate($routes, 'users.show', ['id' => 42]))->toBe('/users/42');
});

it('uses an explicitly configured syntax to generate URLs', function (): void {
    $syntax = $this->createStub(SyntaxParserInterface::class);
    $syntax->method('buildPath')->willReturn('/mounted/users/42');
    $routes = new Routes(syntax: $syntax);
    $routes->addRoute(new RouteRecord('users.show', '/users/[id]', 'handler', ['GET']));

    expect($routes->generate($routes, 'users.show', ['id' => 42]))->toBe('/mounted/users/42');
});
