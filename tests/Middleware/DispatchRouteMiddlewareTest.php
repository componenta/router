<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\Resolver\MiddlewareResolverInterface;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\Factory\DispatchRouteMiddlewareFactory;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MemoizedDispatchRouteMiddleware;
use Componenta\Http\Router\RouteHandler;
use Componenta\Http\Router\RouteRecord;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

it('memoizes resolved route middleware by route name', function () {
    $resolver = new class implements MiddlewareResolverInterface {
        public int $calls = 0;

        public function resolve(mixed $middleware): ?MiddlewareInterface
        {
            if (!$middleware instanceof RouteHandler) {
                return null;
            }

            ++$this->calls;

            return new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(200);
                }
            };
        }
    };

    $middleware = new MemoizedDispatchRouteMiddleware(new MiddlewareFactory($resolver));
    $request = dispatchRouteMiddlewareRequest('memoized');
    $terminal = dispatchRouteMiddlewareTerminal();

    $middleware->process($request, $terminal);
    $middleware->process($request, $terminal);

    expect($resolver->calls)->toBe(1);
});

it('resolves route middleware on every request by default', function () {
    $resolver = new class implements MiddlewareResolverInterface {
        public int $calls = 0;

        public function resolve(mixed $middleware): ?MiddlewareInterface
        {
            if (!$middleware instanceof RouteHandler) {
                return null;
            }

            ++$this->calls;

            return new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(200);
                }
            };
        }
    };

    $middleware = new DispatchRouteMiddleware(new MiddlewareFactory($resolver));
    $request = dispatchRouteMiddlewareRequest('uncached');
    $terminal = dispatchRouteMiddlewareTerminal();

    $middleware->process($request, $terminal);
    $middleware->process($request, $terminal);

    expect($resolver->calls)->toBe(2);
});

it('disables factory-created route memoization when compiled pipeline is disabled in config', function () {
    $resolver = new class implements MiddlewareResolverInterface {
        public int $calls = 0;

        public function resolve(mixed $middleware): ?MiddlewareInterface
        {
            if (!$middleware instanceof RouteHandler) {
                return null;
            }

            ++$this->calls;

            return new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(200);
                }
            };
        }
    };

    $middleware = (new DispatchRouteMiddlewareFactory())(new DispatchRouteFactoryTestContainer([
        ConfigKey::CONFIG => new Config([ConfigKey::COMPILED_PIPELINE => false]),
        MiddlewareFactory::class => new MiddlewareFactory($resolver),
    ]));
    $request = dispatchRouteMiddlewareRequest('factory-uncached');
    $terminal = dispatchRouteMiddlewareTerminal();

    $middleware->process($request, $terminal);
    $middleware->process($request, $terminal);

    expect($middleware)->toBeInstanceOf(DispatchRouteMiddleware::class)
        ->not->toBeInstanceOf(MemoizedDispatchRouteMiddleware::class)
        ->and($resolver->calls)->toBe(2);
});

it('creates memoized dispatch middleware when route memoization is enabled', function () {
    $resolver = new class implements MiddlewareResolverInterface {
        public int $calls = 0;

        public function resolve(mixed $middleware): ?MiddlewareInterface
        {
            if (!$middleware instanceof RouteHandler) {
                return null;
            }

            ++$this->calls;

            return new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(200);
                }
            };
        }
    };

    $middleware = (new DispatchRouteMiddlewareFactory())(new DispatchRouteFactoryTestContainer([
        ConfigKey::CONFIG => new Config([]),
        MiddlewareFactory::class => new MiddlewareFactory($resolver),
    ]));
    $request = dispatchRouteMiddlewareRequest('factory-memoized');
    $terminal = dispatchRouteMiddlewareTerminal();

    $middleware->process($request, $terminal);
    $middleware->process($request, $terminal);

    expect($middleware)->toBeInstanceOf(MemoizedDispatchRouteMiddleware::class)
        ->and($resolver->calls)->toBe(1);
});

it('continues to the next handler when no route match is attached', function () {
    $middleware = new DispatchRouteMiddleware(new MiddlewareFactory(new class implements MiddlewareResolverInterface {
        public function resolve(mixed $middleware): ?MiddlewareInterface
        {
            return null;
        }
    }));

    $response = $middleware->process(new ServerRequest('GET', '/missing'), dispatchRouteMiddlewareTerminal(204));

    expect($response->getStatusCode())->toBe(204);
});

function dispatchRouteMiddlewareRequest(string $routeName): ServerRequestInterface
{
    $route = new RouteRecord($routeName, '/bench', static fn(): ResponseInterface => new Response(200));

    return (new ServerRequest('GET', '/bench'))->withAttribute(
        MatchRouteMiddleware::ATTRIBUTE_MATCH_RESULT,
        new MatchResult($route->name, $route->handler, $route->middlewares, [], $route),
    );
}

function dispatchRouteMiddlewareTerminal(int $status = 404): RequestHandlerInterface
{
    return new readonly class($status) implements RequestHandlerInterface {
        public function __construct(private int $status) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response($this->status);
        }
    };
}

final readonly class DispatchRouteFactoryTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
