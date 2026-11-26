<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

/** @internal Intern identical ordered dynamic-route sets across HTTP methods. */
final class RouteMethodGroups
{
    /**
     * @param array<array-key, array<string, list<int>>> $routes
     * @return array<array-key, string>
     */
    public static function aliases(array $routes): array
    {
        $groups = $aliases = [];
        foreach ($routes as $method => $names) {
            $key = hash('xxh128', serialize($names));
            foreach ($groups[$key] ?? [] as $canonical) {
                if ($routes[$canonical] === $names) {
                    $aliases[$method] = (string) $canonical;
                    continue 2;
                }
            }
            $groups[$key][] = $method;
        }
        return $aliases;
    }

    private function __construct() {}
}
