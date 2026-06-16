<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\Exception\RouterException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Default router exception handler
 *
 * Returns appropriate HTTP responses:
 * - 404 Not Found for RouteNotFoundException
 * - 405 Method Not Allowed for MethodNotAllowedException (with Allow header)
 * - Re-throws other RouterException types
 */
final readonly class RouterExceptionHandler implements RouterExceptionHandlerInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(RouterException $e, ServerRequestInterface $request): ResponseInterface
    {
        if ($e instanceof RouteNotFoundException) {
            return $this->responseFactory->createResponse(404, 'Not Found');
        }

        if ($e instanceof MethodNotAllowedException) {
            return $this->responseFactory
                ->createResponse(405, 'Method Not Allowed')
                ->withHeader('Allow', $e->allowHeader);
        }

        throw $e;
    }
}
