<?php

declare(strict_types=1);

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Router\Resolver\RouteHandlerMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteHandlerMiddlewareCountingExecutor implements CallableExecutorInterface
{
    public int $calls = 0;

    /** @var array<string|int, mixed> */
    public array $params = [];

    public function __construct(
        private readonly mixed $result = null,
    ) {}

    public function resolve(mixed $callable): callable
    {
        if (is_callable($callable)) {
            return $callable;
        }

        throw new InvalidArgumentException(sprintf('Unsupported callable: %s', get_debug_type($callable)));
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        $this->calls++;
        $this->params = $params;

        if ($this->result !== null) {
            return $this->result;
        }

        return $this->resolve($callable)(...array_values($params));
    }
}

function routeHandlerMiddlewareTerminal(): RequestHandlerInterface
{
    return new readonly class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(404);
        }
    };
}

describe('RouteHandlerMiddleware', function () {
    it('delegates route handler invocation to the executor', function () {
        $executor = new RouteHandlerMiddlewareCountingExecutor();
        $middleware = new RouteHandlerMiddleware(
            static fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => new Response(201),
            $executor,
        );

        $request = new ServerRequest('GET', '/');
        $terminal = routeHandlerMiddlewareTerminal();
        $response = $middleware->process($request, $terminal);

        expect($response->getStatusCode())->toBe(201)
            ->and($executor->calls)->toBe(1)
            ->and($executor->params)->toBe([
                'request' => $request,
                'handler' => $terminal,
            ]);
    });

    it('allows a custom executor to own invocation behavior', function () {
        $executor = new RouteHandlerMiddlewareCountingExecutor(new Response(202));
        $middleware = new RouteHandlerMiddleware(
            static fn (): ResponseInterface => throw new RuntimeException('Should not be invoked directly.'),
            $executor,
        );

        $response = $middleware->process(new ServerRequest('GET', '/'), routeHandlerMiddlewareTerminal());

        expect($response->getStatusCode())->toBe(202)
            ->and($executor->calls)->toBe(1);
    });
});
