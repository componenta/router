<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Componenta\Http\Router\Exception\RouterException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Contract for handling router exceptions
 */
interface RouterExceptionHandlerInterface
{
    /**
     * Handle a router exception.
     *
     * Implementations may:
     * - Return an appropriate HTTP response (404, 405, etc.)
     * - Re-throw the exception for global handling
     * - Log and return a generic error response
     *
     * @throws RouterException If the exception should propagate
     */
    public function handle(RouterException $e, ServerRequestInterface $request): ResponseInterface;
}