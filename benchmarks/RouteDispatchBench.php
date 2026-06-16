<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\MiddlewareGroup;
use Componenta\Http\Middleware\Resolver\CompositeResolver;
use Componenta\Http\Middleware\Resolver\MiddlewareGroupResolver;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MemoizedDispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\Resolver\RouteHandlerResolver;
use Componenta\Http\Router\RouteRecord;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Warmup(2)]
final class RouteDispatchBench
{
    private DispatchRouteMiddleware $middleware;
    private DispatchRouteMiddleware $uncachedMiddleware;
    private RequestHandlerInterface $terminal;
    private ServerRequestInterface $requestWithoutMatch;
    private ServerRequestInterface $requestWithHandler;
    private ServerRequestInterface $requestWithMiddlewareGroup;

    public function setUp(): void
    {
        $executor = new BenchmarkCallableExecutor();
        $resolver = new CompositeResolver(
            new MiddlewareGroupResolver(),
            new RouteHandlerResolver($executor),
        );

        $middlewareFactory = new MiddlewareFactory($resolver);
        $this->middleware = new MemoizedDispatchRouteMiddleware($middlewareFactory);
        $this->uncachedMiddleware = new DispatchRouteMiddleware($middlewareFactory);
        $this->terminal = new BenchmarkTerminalHandler();
        $this->requestWithoutMatch = new ServerRequest('GET', '/bench');
        $this->requestWithHandler = $this->requestWithMatch(new RouteRecord(
            'bench.handler',
            '/bench',
            BenchmarkRouteHandler::handle(...),
        ));
        $this->requestWithMiddlewareGroup = $this->requestWithMatch(new RouteRecord(
            'bench.group',
            '/bench',
            BenchmarkRouteHandler::handle(...),
            middlewares: new MiddlewareGroup(
                new BenchmarkRouteMiddleware(),
                new BenchmarkRouteMiddleware(),
            ),
        ));
    }

    #[Revs(10000)]
    #[Groups(['http', 'route-dispatch', 'miss'])]
    public function benchDispatchWithoutMatch(): void
    {
        $this->middleware->process($this->requestWithoutMatch, $this->terminal);
    }

    #[Revs(5000)]
    #[Groups(['http', 'route-dispatch', 'handler'])]
    public function benchDispatchRouteHandler(): void
    {
        $this->middleware->process($this->requestWithHandler, $this->terminal);
    }

    #[Revs(5000)]
    #[Groups(['http', 'route-dispatch', 'handler', 'uncached'])]
    public function benchDispatchRouteHandlerUncached(): void
    {
        $this->uncachedMiddleware->process($this->requestWithHandler, $this->terminal);
    }

    #[Revs(5000)]
    #[Groups(['http', 'route-dispatch', 'middleware-group'])]
    public function benchDispatchRouteWithMiddlewareGroup(): void
    {
        $this->middleware->process($this->requestWithMiddlewareGroup, $this->terminal);
    }

    #[Revs(5000)]
    #[Groups(['http', 'route-dispatch', 'middleware-group', 'uncached'])]
    public function benchDispatchRouteWithMiddlewareGroupUncached(): void
    {
        $this->uncachedMiddleware->process($this->requestWithMiddlewareGroup, $this->terminal);
    }

    private function requestWithMatch(RouteRecord $route): ServerRequestInterface
    {
        return $this->requestWithoutMatch->withAttribute(
            MatchRouteMiddleware::ATTRIBUTE_MATCH_RESULT,
            new MatchResult($route->name, $route->handler, $route->middlewares, [], $route),
        );
    }
}

final readonly class BenchmarkRouteHandler
{
    public static function handle(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(200);
    }
}

final readonly class BenchmarkRouteMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final readonly class BenchmarkTerminalHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(404);
    }
}

final class BenchmarkCallableExecutor implements CallableExecutorInterface
{
    public function resolve(mixed $callable): callable
    {
        if (\is_callable($callable)) {
            return $callable;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported callable: %s', get_debug_type($callable)));
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->resolve($callable)(...$params);
    }
}
