<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

/** @internal Shared extraction and lossless conversion for both matching strategies. */
final class RouteParameters
{
    /** @param list<string> $names */
    public static function extract(array $matches, array $names, array $defaults): array
    {
        foreach ($names as $name) {
            if (isset($matches[$name])) {
                $defaults[$name] = self::cast($matches[$name]);
            }
        }
        return $defaults;
    }

    public static function cast(string $value): string|int|float
    {
        if ($value === '') {
            return $value;
        }

        // Integer: digits with optional leading minus
        $digits = $value[0] === '-' ? substr($value, 1) : $value;
        if ($digits !== '' && ctype_digit($digits)) {
            $number = (int) $value;
            return (string) $number === $value ? $number : $value;
        }

        // Float: digits with decimal point
        $dotPos = strpos($value, '.');
        if ($dotPos !== false) {
            $before = $value[0] === '-' ? substr($value, 1, $dotPos - 1) : substr($value, 0, $dotPos);
            $after = substr($value, $dotPos + 1);
            if ($before !== '' && ctype_digit($before) && $after !== '' && ctype_digit($after)) {
                $number = (float) $value;
                return is_finite($number) && (string) $number === $value ? $number : $value;
            }
        }

        return $value;
    }
    private function __construct() {}
}
