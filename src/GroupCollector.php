<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Arrayable\Arrayable;
use Componenta\Http\Router\Exception\GroupNotFoundException;
use Generator;
use IteratorAggregate;

/**
 * Manages route groups.
 */
final class GroupCollector implements IteratorAggregate, Arrayable
{
    /** @var array<string, RouteGroup> */
    private array $groups = [];

    /**
     * Registers a group.
     */
    public function add(RouteGroup $group): void
    {
        $this->groups[$group->fullName] = $group;
    }

    /**
     * Returns a registered group by name.
     *
     * @throws GroupNotFoundException If group is not registered
     */
    public function get(string $name): RouteGroup
    {
        if (!isset($this->groups[$name])) {
            throw new GroupNotFoundException($name);
        }

        return $this->groups[$name];
    }

    /**
     * Checks if a group is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    /**
     * Returns all registered groups.
     *
     * @return array<string, RouteGroup>
     */
    public function toArray(): array
    {
        return $this->groups;
    }

    /**
     * Returns count of registered groups.
     */
    public function count(): int
    {
        return count($this->groups);
    }

    /**
     * @return Generator<string, RouteGroup>
     */
    public function getIterator(): Generator
    {
        yield from $this->groups;
    }
}