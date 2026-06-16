<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

/**
 * Provides type casting for route parameters.
 */
trait ParameterCaster
{
    /**
     * Cast parameter value to appropriate type.
     *
     * - Integer strings (including negative) -> int
     * - Decimal strings -> float  
     * - Everything else -> string
     */
    private function castParameter(string $value): string|int|float
    {
        if ($value === '') {
            return $value;
        }

        // Integer: digits with optional leading minus
        $digits = $value[0] === '-' ? substr($value, 1) : $value;
        if ($digits !== '' && ctype_digit($digits)) {
            return (int) $value;
        }

        // Float: digits with decimal point
        $dotPos = strpos($value, '.');
        if ($dotPos !== false) {
            $before = $value[0] === '-' ? substr($value, 1, $dotPos - 1) : substr($value, 0, $dotPos);
            $after = substr($value, $dotPos + 1);
            if ($before !== '' && ctype_digit($before) && $after !== '' && ctype_digit($after)) {
                return (float) $value;
            }
        }

        return $value;
    }
}
