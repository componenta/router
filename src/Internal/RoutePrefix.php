<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

/** @internal Conservative first-segment index used by preparation and validation. */
final class RoutePrefix
{
    public static function forPattern(string $path, bool $standardSyntax): string
    {
        if (!$standardSyntax) {
            return '*';
        }
        $first = explode('/', ltrim($path, '/'), 2)[0];
        // Dynamic or optional first segments must be considered for every URI.
        return preg_match('/[\[\]{}<>:]/', $first) === 1 ? '*' : $first;
    }

    private function __construct() {}
}
