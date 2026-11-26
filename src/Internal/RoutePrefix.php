<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

/** @internal Conservative literal path prefix shared by both matching paths. */
final class RoutePrefix
{
    public static function forPattern(string $path, bool $standardSyntax): string
    {
        if (!$standardSyntax) { return '*'; }
        // Only whole literal segments preceding the first possible placeholder
        // are guaranteed to be present, including when a parameter is omitted.
        $literal = substr($path, 0, strcspn($path, '[]{}<>:'));
        $end = strrpos($literal, '/');
        return $end === false || $end === 0 ? '*' : substr($literal, 0, $end);
    }

    /**
     * @param array<string, list<int>> $index
     * @return list<int>
     */
    public static function candidates(array $index, string $uri): array
    {
        $selected = $index['*'] ?? [];
        $merged = false;
        $prefix = $uri;
        while ($prefix !== '') {
            if (isset($index[$prefix])) {
                if ($selected === []) {
                    $selected = $index[$prefix];
                } else {
                    $selected = [...$selected, ...$index[$prefix]];
                    $merged = true;
                }
            }
            $end = strrpos($prefix, '/');
            if ($end === false || $end === 0) { break; }
            $prefix = substr($prefix, 0, $end);
        }
        if ($merged) {
            $selected = array_values(array_unique($selected));
            sort($selected, SORT_NUMERIC);
        }
        return $selected;
    }

    private function __construct() {}
}
