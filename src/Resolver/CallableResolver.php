<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Resolver;

use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\CallableExceptionInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Maps known PSR-15 interfaces to their entry-point methods before resolving.
 *
 * If the handler implements {@see MiddlewareInterface} or {@see RequestHandlerInterface},
 * it is resolved as `[$handler, 'process']` or `[$handler, 'handle']` respectively.
 */
final class CallableResolver
{
    private const array METHODS_MAP = [
        MiddlewareInterface::class => 'process',
        RequestHandlerInterface::class => 'handle',
    ];

    public function __construct(
        private(set) readonly CallableResolverInterface $resolver,
    ) {}

    /**
     * @throws CallableExceptionInterface
     */
    public function resolve(mixed $callable): callable
    {
        $method = array_find(
            self::METHODS_MAP,
            static fn($method, $interface) => is_subclass_of($callable, $interface),
        );

        return $method === null
            ? $this->resolver->resolve($callable)
            : $this->resolver->resolve([$callable, $method]);
    }
}
