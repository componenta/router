<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use InvalidArgumentException;
use Stringable;

/** @internal URI path encoding and complete-pattern round-trip validation. */
final class PathParameter
{
    public static function encode(mixed $parameter, string $name): string
    {
        if (!is_scalar($parameter) && !$parameter instanceof Stringable) {
            throw new InvalidArgumentException(sprintf('Invalid path parameter "%s".', $name));
        }

        $value = (string) $parameter;
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || str_starts_with($value, '/')
            || str_contains($value, '\\')) {
            throw new InvalidArgumentException(sprintf('Invalid path parameter "%s".', $name));
        }

        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('Path parameter "%s" contains a dot segment.', $name));
            }
        }
        // A literal slash is legal only when the complete route expression
        // captures it as part of the supplied multi-segment parameter.
        return implode('/', array_map('rawurlencode', $segments));
    }

    public static function finish(string $path): string
    {
        if (str_starts_with($path, '//') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Generated route must be a path without authority, query or fragment.');
        }
        return $path === '' ? '/' : $path;
    }

    /** @param array<string, string|null> $expected Encoded values; null means omitted. */
    public static function verify(string $path, string $expression, array $expected, string $routeName): string
    {
        if ($expected === []) {
            return $path;
        }

        if ($path === '/') {
            $expression = RouteRegex::withOptionalRoot($expression);
        }
        if (!RouteRegex::match(RouteRegex::anchored($expression), $path, $matches)) {
            throw new InvalidArgumentException(sprintf('Generated path does not match route "%s".', $routeName));
        }

        foreach ($expected as $name => $value) {
            if (($matches[$name] ?? null) !== $value) {
                throw new InvalidArgumentException(sprintf('Path parameter "%s" is ambiguous for route "%s".', $name, $routeName));
            }
        }
        return $path;
    }

    private function __construct() {}
}
