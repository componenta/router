<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use InvalidArgumentException;
use Stringable;

/** @internal Encodes data into a URI path without adding a query, fragment or authority. */
final class PathParameter
{
    public static function encode(mixed $parameter, string $constraint, string $name): string
    {
        if (!is_scalar($parameter) && !$parameter instanceof Stringable) {
            throw new InvalidArgumentException(sprintf('Invalid path parameter "%s".', $name));
        }

        $value = (string) $parameter;
        $regex = RouteRegex::anchored($constraint);
        if (!RouteRegex::match($regex, $value)
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || str_starts_with($value, '/')
            || str_contains($value, '\\')) {
            throw new InvalidArgumentException(sprintf('Invalid path parameter "%s".', $name));
        }

        // A constraint that accepts slash is explicitly a multi-segment token.
        // Encode its segments independently and do not collapse them.
        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('Path parameter "%s" contains a dot segment.', $name));
            }
        }
        $encoded = implode('/', array_map('rawurlencode', $segments));
        if (!RouteRegex::match($regex, $encoded)) {
            throw new InvalidArgumentException(sprintf('Encoded path parameter "%s" does not match its constraint.', $name));
        }
        return $encoded;
    }

    public static function finish(string $path): string
    {
        if (str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Generated route must not contain a URI authority.');
        }
        return $path === '' ? '/' : $path;
    }

    private function __construct() {}
}
