<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DependencyKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\ResolutionException;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Responder;
use Componenta\Http\Router\ConfigProvider;
use Componenta\Http\Router\RouteHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

function optionalResponderContainer(array $services = [], array $factories = []): ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new \Componenta\Http\Middleware\ConfigProvider(),
        new ConfigProvider(),
        static fn (): array => [DependencyKey::DEPENDENCIES => [
            DependencyKey::SERVICES => $services,
            DependencyKey::FACTORIES => $factories,
        ]],
    );
    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

function unusedRouteHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            throw new LogicException('This route must return its own response.');
        }
    };
}

it('does not initialize a responder for PSR handler results', function (string $kind, bool $registered): void {
    $resolved = 0;
    $container = optionalResponderContainer(factories: $registered ? [
        Responder::class => static function () use (&$resolved): never {
            ++$resolved;
            throw new LogicException('An unused responder must not be resolved.');
        },
    ] : []);
    $response = new Response(202);
    $result = match ($kind) {
        'response' => $response,
        'middleware' => new class ($response) implements MiddlewareInterface {
            public function __construct(private ResponseInterface $response) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->response;
            }
        },
        'handler' => new class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return $this->response; }
        },
    };
    $middleware = $container->get(MiddlewareFactory::class)->createMiddleware(new RouteHandler(static fn () => $result));

    expect($container->has(ResponseFactoryInterface::class))->toBeFalse()
        ->and($middleware->process(new ServerRequest('GET', '/'), unusedRouteHandler()))->toBe($response)
        ->and($resolved)->toBe(0);
})->with(['response', 'middleware', 'handler'])->with([false, true]);

it('uses the explicitly registered responder for non-PSR values', function (mixed $value, string $body, bool $lazy): void {
    $responses = new class implements ResponseFactoryInterface {
        public int $calls = 0;
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            ++$this->calls;
            return new Response($code, ['X-Responder' => 'application']);
        }
    };
    $responder = new Responder($responses, new Psr17Factory());
    $initialized = 0;
    if ($lazy) {
        $backing = $responder;
        $responder = (new ReflectionClass(Responder::class))->newLazyProxy(static function () use ($backing, &$initialized): Responder {
            ++$initialized;
            return $backing;
        });
    }
    // The responder already owns its factories; no PSR-17 services need to be registered.
    $container = optionalResponderContainer([Responder::class => $responder]);
    $middleware = $container->get(MiddlewareFactory::class)->createMiddleware(new RouteHandler(static fn () => $value));
    expect($container->has(ResponseFactoryInterface::class))->toBeFalse()
        ->and($responses->calls)->toBe(0)->and($initialized)->toBe(0);

    for ($i = 1; $i <= 2; ++$i) {
        $response = $middleware->process(new ServerRequest('GET', '/'), unusedRouteHandler());
        expect($response->getHeaderLine('X-Responder'))->toBe('application')
            ->and((string) $response->getBody())->toBe($body)
            ->and($responses->calls)->toBe($i)
            ->and($initialized)->toBe($lazy ? 1 : 0);
    }
})->with([
    'string' => ['hello', 'hello'],
    'array' => [['answer' => 42], '{"answer":42}'],
])->with([false, true]);

it('propagates the configured responder factory failure when normalization is needed', function (): void {
    $failure = new LogicException('The configured responder factory failed.');
    $resolved = 0;
    $container = optionalResponderContainer(factories: [
        Responder::class => static function () use ($failure, &$resolved): never {
            ++$resolved;
            throw $failure;
        },
    ]);
    $middleware = $container->get(MiddlewareFactory::class)->createMiddleware(new RouteHandler(static fn () => ['value' => 'response']));
    expect($resolved)->toBe(0);

    try {
        $middleware->process(new ServerRequest('GET', '/'), unusedRouteHandler());
        throw new RuntimeException('The configured failure was suppressed.');
    } catch (ResolutionException $error) {
        $cause = $error;
        while ($cause->getPrevious() !== null) { $cause = $cause->getPrevious(); }
        expect($cause)->toBe($failure)->and($resolved)->toBe(1);
    }
});

it('propagates failures raised by the configured responder itself', function (): void {
    $failure = new LogicException('The configured response factory failed.');
    $responses = new class ($failure) implements ResponseFactoryInterface {
        public function __construct(private Throwable $failure) {}
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface { throw $this->failure; }
    };
    $container = optionalResponderContainer([Responder::class => new Responder($responses, new Psr17Factory())]);
    $middleware = $container->get(MiddlewareFactory::class)->createMiddleware(new RouteHandler(static fn () => ['value' => 'response']));

    try {
        $middleware->process(new ServerRequest('GET', '/'), unusedRouteHandler());
        throw new RuntimeException('The configured failure was suppressed.');
    } catch (LogicException $error) {
        expect($error)->toBe($failure);
    }
});
