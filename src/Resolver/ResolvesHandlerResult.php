<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Resolver;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;

/**
 * Converts a route handler's return value into a PSR-7 response.
 *
 * Handles ResponseInterface pass-through, MiddlewareInterface/RequestHandlerInterface
 * delegation, and optional Responder fallback for arbitrary return values.
 *
 * Requires the using class to declare a `$responder` property of type `?Responder`.
 */
trait ResolvesHandlerResult
{
    private function resolveResult(
        mixed $result,
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if ($result instanceof MiddlewareInterface) {
            return $result->process($request, $handler);
        }

        if ($result instanceof RequestHandlerInterface) {
            return $result->handle($request);
        }

        if ($this->responder !== null) {
            return $this->responder->respond(content: $result);
        }

        throw new UnexpectedValueException(sprintf(
            'Route handler must return %s, %s, or %s. Got: %s',
            ResponseInterface::class,
            MiddlewareInterface::class,
            RequestHandlerInterface::class,
            get_debug_type($result),
        ));
    }
}
