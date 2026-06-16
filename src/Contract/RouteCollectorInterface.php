<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

use Componenta\Arrayable\Arrayable;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Exception\RouteNotRegisteredException;

/**
 * Contract for read-only access to a route collection.
 *
 * Provides methods to query, iterate, and retrieve routes by name.
 * Implementations may be mutable (Routes) or immutable (CompiledRoutes).
 *
 * @extends \IteratorAggregate<string, RouteRecord>
 */
interface RouteCollectorInterface extends Arrayable, \Countable, \IteratorAggregate
{
    /**
     * Check if a route with the given name exists.
     *
     * @param string $name The route name to check
     */
    public function has(string $name): bool;

    /**
     * Get a route by its name.
     *
     * @param string $name The route name
     * @return RouteRecord The route definition
     *
     * @throws RouteNotRegisteredException When route is not found
     */
    public function getRoute(string $name): RouteRecord;

    /**
     * Get all routes as an associative array.
     *
     * @return array<string, RouteRecord> Routes indexed by name
     */
    public function toArray(): array;
}
