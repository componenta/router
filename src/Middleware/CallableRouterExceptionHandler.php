<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Closure;
use Componenta\Http\Router\Exception\RouterException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Callback-based router exception handler
 *
 * Allows custom handling via closure:
 *
 * ```php
 * $handler = new CallableRouterExceptionHandler(
 *     fn(RouterException $e, ServerRequestInterface $req) => match (true) {
 *         $e instanceof RouteNotFoundException => new JsonResponse(['error' => 'Not found'], 404),
 *         $e instanceof MethodNotAllowedException => new JsonResponse(['error' => 'Method not allowed'], 405),
 *         default => throw $e,
 *     }
 * );
 * ```
 */
final readonly class CallableRouterExceptionHandler implements RouterExceptionHandlerInterface
{
    /**
     * @param Closure(RouterException, ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(
        private Closure $handler,
    ) {}

    public function handle(RouterException $e, ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($e, $request);
    }
}