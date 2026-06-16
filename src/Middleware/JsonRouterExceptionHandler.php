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
 * JSON router exception handler
 *
 * Returns JSON error responses:
 * - 404: {"error": "Not Found", "path": "/..."}
 * - 405: {"error": "Method Not Allowed", "method": "POST", "allowed": ["GET", "PUT"]}
 */
final readonly class JsonRouterExceptionHandler implements RouterExceptionHandlerInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private int $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) {}

    public function handle(RouterException $e, ServerRequestInterface $request): ResponseInterface
    {
        if ($e instanceof RouteNotFoundException) {
            return $this->json(404, [
                'error' => 'Not Found',
                'path' => $e->uri,
            ]);
        }

        if ($e instanceof MethodNotAllowedException) {
            return $this->json(405, [
                'error' => 'Method Not Allowed',
                'method' => $e->method,
                'allowed' => $e->allowedMethods,
            ])->withHeader('Allow', $e->allowHeader);
        }

        throw $e;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json');

        $response->getBody()->write(
            json_encode($data, $this->jsonFlags | JSON_THROW_ON_ERROR)
        );

        return $response;
    }
}
