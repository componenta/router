<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Exception\RoutePatternException;

/** @internal Strict PCRE boundary shared by source and prepared matching. */
final class RouteRegex
{
    public static function anchored(string $expression, string $modifiers = ''): string
    {
        return '#\A(?:' . $expression . ')\z#' . $modifiers;
    }

    public static function match(string $regex, string $subject, ?array &$matches = null): bool
    {
        // Do not confuse an invalid expression or an exhausted PCRE limit with a miss.
        $result = @preg_match($regex, $subject, $matches, PREG_UNMATCHED_AS_NULL);
        if ($result === false) {
            throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
        }
        return $result === 1;
    }

    public static function validate(string $regex): void
    {
        self::match($regex, '');
    }

    private function __construct() {}
}
