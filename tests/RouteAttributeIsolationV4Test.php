<?php

declare(strict_types=1);

use Componenta\Http\Router\Exception\RouteAttributeConflictException;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Router;
use Componenta\Http\Router\Routes;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

it('never replaces an existing request attribute with route input', function (): void {
    $routes = new Routes();
    $routes->addRoute(RouteRecord::get('item', '/items/{user}', 'Controller'));
    $middleware = new MatchRouteMiddleware(Router::fromDnf($routes));
    $request = (new ServerRequest('GET', '/items/attacker'))->withAttribute('user', 'trusted');
    $handler = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            throw new LogicException('The downstream handler must not run.');
        }
    };
    expect(fn () => $middleware->process($request, $handler))->toThrow(RouteAttributeConflictException::class);
    expect($request->getAttribute('user'))->toBe('trusted');
});


it('retains the selected match parameters when a custom matcher changes caller-owned references', function (): void {
    $route = RouteRecord::get('item', '/items/{id}', 'Controller');
    $routes = (new Routes())->addRoute($route);
    $matcher = new class($route) implements \Componenta\Http\Router\Contract\MatcherInterface {
        public int $id = 42;
        public function __construct(private readonly RouteRecord $route) {}

        public function match(\Componenta\Http\Router\Contract\RouteCollectorInterface $routes, string $uri, string $method): \Componenta\Http\Router\MatchResult
        {
            return new \Componenta\Http\Router\MatchResult(
                name: $this->route->name,
                handler: $this->route->handler,
                middlewares: $this->route->middlewares,
                parameters: ['id' => &$this->id],
                rr: $this->route,
            );
        }
    };
    $middleware = new MatchRouteMiddleware(new Router($routes, $matcher, $routes));
    $response = new \Nyholm\Psr7\Response(204);
    $handler = new class($matcher, $response) implements RequestHandlerInterface {
        public function __construct(private readonly object $matcher, private readonly ResponseInterface $response) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->matcher->id = 99;
            $match = MatchRouteMiddleware::getMatchResultFromRequest($request);
            expect($match->parameters)->toBe(['id' => 42])
                ->and($request->getAttribute('id'))->toBe(42);
            return $this->response;
        }
    };

    expect($middleware->process(new ServerRequest('GET', '/items/42'), $handler))->toBe($response)
        ->and($matcher->id)->toBe(99);
});

it('detaches only top-level match parameter references without changing nested values or object identities', function (): void {
    $route = RouteRecord::get('item', '/items/{id}', 'Controller');
    $id = 42;
    $object = new stdClass();
    $nested = 'first';
    $parameters = ['id' => &$id, 'object' => $object, 'nested' => ['value' => &$nested]];
    $result = new \Componenta\Http\Router\MatchResult($route->name, $route->handler, $route->middlewares, $parameters, $route);
    $id = 99;
    $parameters['object'] = new stdClass();
    $nested = 'second';

    expect($result->parameters['id'])->toBe(42)
        ->and($result->parameters['object'])->toBe($object)
        ->and($result->parameters['nested']['value'])->toBe('second')
        ->and($result->route)->toBe($route);
});
