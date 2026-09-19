<?php
declare(strict_types=1);
namespace Componenta\Http\Router\Locator;

use Closure;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Contract\RouteCollectorInterface;
use Componenta\Http\Router\Contract\RouteLocatorInterface;

/** Loads an optional optimization; the source is resolved only when it is needed. */
final class CachedRouteLocator implements RouteLocatorInterface
{
    private ?CompiledRoutes $routes = null;
    /** @param Closure(): RouteLocatorInterface $source */
    public function __construct(private readonly string $file, private readonly Closure $source) {}

    public function getRoutes(array $context = []): RouteCollectorInterface
    {
        if ($context !== []) {
            return ($this->source)()->getRoutes($context);
        }
        $this->routes ??= CompiledRoutes::tryFromCache($this->file);
        return $this->routes ?? ($this->source)()->getRoutes();
    }
}
