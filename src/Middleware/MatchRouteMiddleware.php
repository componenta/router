<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Middleware;

use Componenta\Http\Router\Exception\RouterException;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Router;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route Matching Middleware
 *
 * Matches incoming HTTP requests against registered routes.
 * On successful match, attaches MatchResult to request attributes.
 * On failure, either continues to next handler or re-throws exception.
 */
final class MatchRouteMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute key for storing the MatchResult.
     */
    public const string ATTRIBUTE_MATCH_RESULT = MatchResult::class;

    public function __construct(
        private readonly Router $router,
        private readonly RouterExceptionHandlerInterface $exceptionHandler = new ThrowingRouterExceptionHandler,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri()->getPath();
        $method = $request->getMethod();

        try {
            $result = $this->router->match($uri, $method);

            // Add match result to request attributes
            $request = $request->withAttribute(self::ATTRIBUTE_MATCH_RESULT, $result);

            // Add route parameters as individual attributes
            foreach ($result->parameters as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

        } catch (RouterException $e) {
            return $this->exceptionHandler->handle($e, $request);
        }

        return $handler->handle($request);
    }

    /**
     * Extract MatchResult from request attributes.
     */
    public static function getMatchResultFromRequest(ServerRequestInterface $request): ?MatchResult
    {
        $result = $request->getAttribute(self::ATTRIBUTE_MATCH_RESULT);
        return $result instanceof MatchResult ? $result : null;
    }

    public static function createFromContainer(ContainerInterface $container): self
    {
        return new self($container->get(Router::class), $container->get(RouterExceptionHandlerInterface::class));
    }
}
