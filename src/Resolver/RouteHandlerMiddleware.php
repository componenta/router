<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Resolver;

use Componenta\DI\CallableExecutorInterface;
use Componenta\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dispatches a route handler directly via DI-aware callable executor.
 *
 * Does not use the interceptor pipeline - suitable for lightweight routes
 * that don't need attribute-based interceptors.
 */
final readonly class RouteHandlerMiddleware implements MiddlewareInterface
{
    use ResolvesHandlerResult;

    /** @var callable */
    private mixed $handler;

    public function __construct(
        callable $handler,
        private CallableExecutorInterface $executor,
        private ?Responder $responder = null,
    ) {
        // A callable array may contain references to the receiver or method.
        // Preserve the validated callable while retaining object identity.
        $this->handler = is_array($handler)
            ? array_map(static fn (mixed $value): mixed => $value, $handler)
            : $handler;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->executor->call($this->handler, [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $handler,
        ]);

        return $this->resolveResult($result, $request, $handler);
    }
}
