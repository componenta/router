<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\HttpMethod;
use Componenta\Http\Router\Exception\RoutePatternException;
use InvalidArgumentException;

/** @internal Shared validation of route definition slots, independent of object construction. */
final class RouteDefinition
{
    public static function validateName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Route name must be non-empty and must not contain NUL.');
        }
    }

    public static function normalizePath(string $path): string
    {
        // Interior slashes belong to the path/template, including inline PCRE.
        return '/' . trim($path, '/');
    }

    /** @return list<string> */
    public static function normalizeMethods(array $methods): array
    {
        if ($methods === []) { throw new InvalidArgumentException('Route must have at least one HTTP method'); }
        $normalized = [];
        foreach ($methods as $method) {
            if (!is_string($method)) { throw new InvalidArgumentException('HTTP method must be a string'); }
            $method = HttpMethod::normalize($method);
            if (!in_array($method, $normalized, true)) { $normalized[] = $method; }
        }
        return $normalized;
    }

    /** @return array<string, string> */
    public static function tokens(array $tokens, ?array &$checked = null): array
    {
        $snapshot = [];
        foreach ($tokens as $name => $pattern) {
            if (!is_string($name) || $name === '') { throw new InvalidArgumentException('Token name must be a non-empty string'); }
            if (!is_string($pattern)) { throw new InvalidArgumentException("Token pattern for '$name' must be a string"); }
            if ($checked === null || !isset($checked[$pattern])) {
                try {
                    RouteRegex::validate(RouteRegex::anchored($pattern));
                } catch (RoutePatternException $error) {
                    throw new InvalidArgumentException("Invalid regex pattern for token '$name': $pattern", previous: $error);
                }
                if ($checked !== null) { $checked[$pattern] = true; }
            }
            $snapshot[$name] = $pattern;
        }
        return $snapshot;
    }

    /** @return array<string, scalar|null> */
    public static function defaults(array $defaults): array
    {
        $snapshot = [];
        foreach ($defaults as $name => $value) {
            if (!is_string($name) || $name === '') { throw new InvalidArgumentException('Default parameter name must be a non-empty string'); }
            if (!is_scalar($value) && $value !== null) { throw new InvalidArgumentException("Default value for '$name' must be scalar or null"); }
            $snapshot[$name] = $value;
        }
        return $snapshot;
    }

    private function __construct() {}
}
