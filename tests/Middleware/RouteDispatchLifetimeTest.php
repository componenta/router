<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Tests\Middleware;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\Resolver\MiddlewareResolverInterface;
use Componenta\Http\Router\ConfigKey;
use Componenta\Http\Router\ConfigProvider;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\RouteRecord;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteDispatchLifetimeTest extends TestCase
{
    #[DataProvider('optimizationModes')]
    public function testResolverControlsMiddlewareLifetimeOnRepeatedRequests(bool $optimized): void
    {
        $dispatch = $this->dispatch(new class implements MiddlewareResolverInterface {
            public function resolve(mixed $middleware): ?MiddlewareInterface
            {
                return new class implements MiddlewareInterface {
                    private int $requests = 0;

                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return new Response(200, [], (string) ++$this->requests);
                    }
                };
            }
        }, $optimized);

        self::assertSame('1', (string) $dispatch->process($this->request(), $this->terminal())->getBody());
        self::assertSame('1', (string) $dispatch->process($this->request(), $this->terminal())->getBody());
    }

    #[DataProvider('optimizationModes')]
    public function testLaterResolverFailuresAreNotHiddenByEarlierSuccess(bool $optimized): void
    {
        $failure = new \RuntimeException('The current middleware cannot be resolved.');
        $dispatch = $this->dispatch(new class($failure) implements MiddlewareResolverInterface {
            private bool $resolved = false;
            public function __construct(private \RuntimeException $failure) {}

            public function resolve(mixed $middleware): ?MiddlewareInterface
            {
                if ($this->resolved) { throw $this->failure; }
                $this->resolved = true;
                return new class implements MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return new Response(200);
                    }
                };
            }
        }, $optimized);

        self::assertSame(200, $dispatch->process($this->request(), $this->terminal())->getStatusCode());
        try {
            $dispatch->process($this->request(), $this->terminal());
            self::fail('A later resolution failure must reach the caller.');
        } catch (\Componenta\Http\Middleware\Exception\MiddlewareResolutionException $error) {
            self::assertSame($failure, $error->getPrevious());
        }
    }

    public function testExplicitlySharedMiddlewareKeepsItsState(): void
    {
        $dispatch = $this->dispatch(new class implements MiddlewareResolverInterface {
            private MiddlewareInterface $middleware;
            public function __construct()
            {
                $this->middleware = new class implements MiddlewareInterface {
                    private int $requests = 0;
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return new Response(200, [], (string) ++$this->requests);
                    }
                };
            }
            public function resolve(mixed $middleware): ?MiddlewareInterface { return $this->middleware; }
        }, true);

        self::assertSame('1', (string) $dispatch->process($this->request(), $this->terminal())->getBody());
        self::assertSame('2', (string) $dispatch->process($this->request(), $this->terminal())->getBody());
    }

    public function testPassesRequestsWithoutAMatchToTheNextHandler(): void
    {
        $dispatch = $this->dispatch(new class implements MiddlewareResolverInterface {
            public function resolve(mixed $middleware): ?MiddlewareInterface
            {
                throw new \LogicException('No route middleware should be resolved.');
            }
        }, true);

        self::assertSame(404, $dispatch->process(new ServerRequest('GET', '/missing'), $this->terminal())->getStatusCode());
    }

    public static function optimizationModes(): iterable
    {
        yield 'source routes' => [false];
        yield 'optimized routes' => [true];
    }

    private function dispatch(MiddlewareResolverInterface $resolver, bool $optimized): DispatchRouteMiddleware
    {
        $composition = (new ConfigFactory())->create(
            new Environment([]),
            new ConfigProvider(),
            static fn (): array => [
                ConfigKey::COMPILED_PIPELINE => $optimized,
                \Componenta\Config\ConfigKey::DEPENDENCIES => [
                    \Componenta\Config\ConfigKey::SERVICES => [MiddlewareFactory::class => new MiddlewareFactory($resolver)],
                ],
            ],
        );

        return (new ContainerFactory())->create($composition->config, $composition->dependencies)->get(DispatchRouteMiddleware::class);
    }

    private function request(): ServerRequestInterface
    {
        $route = RouteRecord::get('item', '/items/[id]', 'ItemHandler');

        return (new ServerRequest('GET', '/items/42'))->withAttribute(
            MatchRouteMiddleware::ATTRIBUTE_MATCH_RESULT,
            new MatchResult($route->name, $route->handler, $route->middlewares, ['id' => 42], $route),
        );
    }

    private function terminal(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };
    }
}
