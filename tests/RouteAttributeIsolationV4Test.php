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
