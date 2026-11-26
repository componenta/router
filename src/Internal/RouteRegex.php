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

    /** @param list<string> $expressions */
    public static function combined(array $expressions): string
    {
        $branches = [];
        foreach ($expressions as $index => $expression) {
            $branches[] = '(?:' . $expression . ')(*MARK:r' . $index . ')';
        }
        return self::anchored(implode('|', $branches), 'J');
    }

    public static function match(string $regex, string $subject, ?array &$matches = null, bool $protectMark = false): bool
    {
        // Do not confuse an invalid expression or an exhausted PCRE limit with a miss.
        $flags = PREG_UNMATCHED_AS_NULL | ($protectMark ? PREG_OFFSET_CAPTURE : 0);
        $result = @preg_match($regex, $subject, $matches, $flags);
        if ($result === false) {
            throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
        }
        if ($result === 1 && $protectMark) {
            // Named captures carry offsets; PCRE's special MARK metadata is a string.
            if (isset($matches['MARK']) && !is_array($matches['MARK'])) {
                throw new RoutePatternException('PCRE MARK metadata conflicts with the route parameter "MARK".');
            }
            $matches = array_map(static fn (array $capture): mixed => $capture[0], $matches);
        }
        return $result === 1;
    }

    public static function validate(string $regex): void
    {
        self::probe($regex);
    }

    /** Validate a complete expression and recognize the omitted optional root. */
    public static function withOptionalRoot(string $expression): string
    {
        return self::probe(self::anchored($expression)) ? '(?:' . $expression . '|/)' : $expression;
    }

    private static function probe(string $regex): bool
    {
        // Configuration errors have a stable domain exception, even if
        // the application converts PHP warnings to exceptions.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return self::match($regex, '');
        } finally {
            restore_error_handler();
        }
    }

    private function __construct() {}
}
