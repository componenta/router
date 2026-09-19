<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;

it('preserves matching and generation configuration for an external collector', function (
    Compiler $compiler,
    ?SyntaxParserInterface $syntax,
    string $path,
    array $parameters,
    string $expectedUrl,
    string $uri,
    array $expectedParameters,
    bool $empty,
): void {
    $record = RouteRecord::get('item', $path, 'handler');
    $source = new Routes($compiler, $syntax);
    if (!$empty) {
        $source->addRoute($record);
    }
    $records = (new Routes())->addRoute($record);
    $external = new class($records) implements RouteCollectorInterface {
        public function __construct(private Routes $routes) {}
        public function has(string $name): bool { return $this->routes->has($name); }
        public function getRoute(string $name): RouteRecord { return $this->routes->getRoute($name); }
        public function toArray(): array { return $this->routes->toArray(); }
        public function count(): int { return $this->routes->count(); }
        public function getIterator(): Traversable { return $this->routes->getIterator(); }
    };
    $file = tempnam(sys_get_temp_dir(), 'external_route_config_');

    try {
        (new RouteCacheGenerator($compiler))->generate($source, $file);
        foreach ([$source, CompiledRoutes::fromArray(require $file), CompiledRoutes::fromCache($file), CompiledRoutes::tryFromCache($file)] as $routes) {
            expect($routes->generate($external, 'item', $parameters))->toBe($expectedUrl)
                ->and($routes->match($external, $uri, 'GET')->parameters)->toBe($expectedParameters);
        }
    } finally {
        unlink($file);
    }
})->with([
    'compiler syntax' => [new Compiler(syntax: new CurlySyntax()), null, '/literal/[id]', [], '/literal/[id]', '/literal/[id]', []],
    'explicit curly generator' => [new Compiler(), new CurlySyntax(), '/literal/[id]', [], '/literal/[id]', '/literal/17', ['id' => 17]],
    'explicit square generator' => [new Compiler(syntax: new CurlySyntax()), new SquareBracketSyntax(), '/items/[id]', ['id' => 17], '/items/17', '/items/[id]', []],
    'custom default constraint' => [new Compiler(defaultPatterns: ['id' => '[A-Z]+']), null, '/items/{id}', ['id' => 'ABC'], '/items/ABC', '/items/ABC', ['id' => 'ABC']],
    'standard configuration' => [new Compiler(), null, '/items/{id}', ['id' => 17], '/items/17', '/items/17', ['id' => 17]],
])->with(['populated' => false, 'empty' => true]);

it('keeps source constraint failures when matching and generating through an external collector', function (): void {
    $compiler = new Compiler(defaultPatterns: ['id' => '[A-Z]+']);
    $source = new Routes($compiler);
    $source->addRoute(RouteRecord::get('item', '/items/{id}', 'handler'));
    $external = new class($source) implements RouteCollectorInterface {
        public function __construct(private Routes $routes) {}
        public function has(string $name): bool { return $this->routes->has($name); }
        public function getRoute(string $name): RouteRecord { return $this->routes->getRoute($name); }
        public function toArray(): array { return $this->routes->toArray(); }
        public function count(): int { return $this->routes->count(); }
        public function getIterator(): Traversable { return $this->routes->getIterator(); }
    };
    $cached = CompiledRoutes::fromArray((new RouteCacheGenerator($compiler))->compile($source));

    foreach ([$source, $cached] as $routes) {
        expect(fn () => $routes->generate($external, 'item'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $routes->generate($external, 'item', ['id' => 17]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $routes->match($external, '/items/17', 'GET'))->toThrow(\Componenta\Http\Router\Exception\RouteNotFoundException::class)
            ->and(fn () => $routes->match($external, '/items/ABC', 'POST'))->toThrow(\Componenta\Http\Router\Exception\MethodNotAllowedException::class);
    }
});
