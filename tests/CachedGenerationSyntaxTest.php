<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;
use Componenta\Http\Router\Locator\CachedRouteLocator;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;
use Componenta\Http\Router\Syntax\AngleBracketSyntax;
use Componenta\Http\Router\Syntax\ColonSyntax;

it('preserves the configured generation syntax after loading a route cache', function (SyntaxParserInterface $syntax, string $path, array $parameters, string $expected): void {
    $compiler = new Compiler(syntax: $syntax);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('item', $path, 'handler'));
    $file = tempnam(sys_get_temp_dir(), 'route_generation_syntax_');

    try {
        (new RouteCacheGenerator($compiler))->generate($source, $file);
        foreach ([$source, CompiledRoutes::fromCache($file)] as $routes) {
            expect($routes->generate($routes, 'item', $parameters))->toBe($expected)
                ->and($routes->match($routes, $expected, 'GET')->name)->toBe('item');
        }
    } finally {
        unlink($file);
    }
})->with([
    'curly syntax keeps square brackets literal' => [new CurlySyntax(), '/literal/[id]', [], '/literal/[id]'],
    'unused parameters keep literals unchanged' => [new CurlySyntax(), '/literal/[id]', ['id' => 17], '/literal/[id]'],
    'curly parameter' => [new CurlySyntax(), '/items/{id}', ['id' => 17], '/items/17'],
    'default composite syntax' => [new CompositeSyntax(), '/items/[id]', ['id' => 17], '/items/17'],
    'square syntax keeps curly braces literal' => [new SquareBracketSyntax(), '/literal/{id}', [], '/literal/{id}'],
    'angle syntax keeps square brackets literal' => [new AngleBracketSyntax(), '/literal/[id]', [], '/literal/[id]'],
    'colon syntax keeps square brackets literal' => [new ColonSyntax(), '/literal/[id]', [], '/literal/[id]'],
]);

it('keeps explicit generation syntax ahead of the compiler syntax', function (): void {
    $compiler = new Compiler(syntax: new CurlySyntax());
    $source = new Routes($compiler, syntax: new SquareBracketSyntax());
    $source->addRoute(RouteRecord::get('item', '/items/[id]', 'handler'));
    $file = tempnam(sys_get_temp_dir(), 'explicit_generation_syntax_');

    try {
        (new RouteCacheGenerator($compiler))->generate($source, $file);
        foreach ([$source, CompiledRoutes::fromArray(require $file), CompiledRoutes::fromCache($file), CompiledRoutes::tryFromCache($file)] as $routes) {
            expect($routes->generate($routes, 'item', ['id' => 17]))->toBe('/items/17')
                ->and($routes->match($routes, '/items/[id]', 'GET')->name)->toBe('item');
            expect(fn () => $routes->generate($routes, 'item'))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        unlink($file);
    }
});

it('uses the source when cached generation syntax is invalid', function (mixed $invalid): void {
    $compiler = new Compiler(syntax: new CurlySyntax());
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('literal', '/literal/[id]', 'handler'));
    $data = (new RouteCacheGenerator($compiler))->compile($source);
    $data['generationSyntax'] = $invalid;
    $file = tempnam(sys_get_temp_dir(), 'invalid_generation_syntax_');

    try {
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        $locator = new CachedRouteLocator($file, static fn () => new class($source) implements RouteLocatorInterface {
            public function __construct(private Routes $routes) {}
            public function getRoutes(array $context = []): RouteCollectorInterface { return $this->routes; }
        });
        $routes = $locator->getRoutes();
        expect($routes)->toBe($source)
            ->and($routes->generate($routes, 'literal'))->toBe('/literal/[id]');
    } finally {
        unlink($file);
    }
})->with([
    'null syntax' => [null],
    'array syntax' => [[]],
    'unknown syntax' => ['UnknownSyntax'],
]);

it('does not overwrite a cache when custom syntax cannot be preserved', function (): void {
    $syntax = new CompositeSyntax([new CurlySyntax()], new CurlySyntax());
    $compiler = new Compiler(syntax: $syntax);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('literal', '/literal/[id]', 'handler'));
    $file = tempnam(sys_get_temp_dir(), 'unsupported_generation_syntax_');
    file_put_contents($file, 'previous cache');

    try {
        expect(fn () => (new RouteCacheGenerator($compiler))->generate($source, $file))
            ->toThrow(RuntimeException::class, 'Route generation syntax cannot be preserved');
        expect(file_get_contents($file))->toBe('previous cache')
            ->and($source->generate($source, 'literal'))->toBe('/literal/[id]');
    } finally {
        unlink($file);
    }
});
