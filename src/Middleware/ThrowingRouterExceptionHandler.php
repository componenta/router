<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Componenta\Http\Router\Exception\RouterException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Re-throwing exception handler
 *
 * Simply re-throws all router exceptions.
 * Use when exceptions should be handled by a global error handler.
 */
final readonly class ThrowingRouterExceptionHandler implements RouterExceptionHandlerInterface
{
    public function handle(RouterException $e, ServerRequestInterface $request): ResponseInterface
    {
        throw $e;
    }
}