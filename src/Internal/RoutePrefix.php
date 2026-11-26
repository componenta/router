<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Contract\CompilerInterface;

/** @internal Conservative literal path discriminator shared by both matching paths. */
final class RoutePrefix
{
    public static function forPattern(string $path, bool $standardSyntax): string
    {
        if (!$standardSyntax) { return '*'; }
        $literal = substr($path, 0, strcspn($path, '[]{}<>:'));
        $end = strrpos($literal, '/');
        return $end === false || $end === 0 ? '*' : substr($literal, 0, $end);
    }

    /** @param list<string> $names */
    public static function indexedKey(string $path, bool $standardSyntax, array $tokens, array $names): string
    {
        $prefix = self::forPattern($path, $standardSyntax);
        if (!$standardSyntax) { return $prefix; }
        $segment = substr($path, strrpos($path, '/') + 1);
        if ($segment === '' || strspn($segment, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._~-') !== strlen($segment)) { return $prefix; }
        // Only whole literal segments in simple built-in templates and known
        // safe token expressions qualify. Uncertain expressions retain the prefix-only path.
        foreach (explode('/', substr($path, 1)) as $part) {
            if ($part === '') { return $prefix; }
            if (strspn($part, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._~-') === strlen($part)) { continue; }
            $name = match ($part[0]) {
                '{' => str_ends_with($part, '}') ? self::withoutOptionalSuffix(substr($part, 1, -1)) : '',
                '[' => str_ends_with($part, ']') ? (str_starts_with($part, '[?') ? substr($part, 2, -1) : substr($part, 1, -1)) : '',
                '<' => str_ends_with($part, '>') ? self::withoutOptionalSuffix(substr($part, 1, -1)) : '',
                ':' => self::withoutOptionalSuffix(substr($part, 1)),
                default => '',
            };
            if (!InlineConstraint::isName($name)) { return $prefix; }
        }
        foreach ($names as $name) {
            $token = $tokens[$name] ?? '[^/]+';
            if ($token !== '[^/]+' && !in_array($token, CompilerInterface::DEFAULT_PATTERNS, true)) { return $prefix; }
        }
        return self::suffixKey($prefix, $segment);
    }

    private static function withoutOptionalSuffix(string $value): string
    {
        return str_ends_with($value, '?') ? substr($value, 0, -1) : $value;
    }

    public static function accepts(string $key, string $uri): bool
    {
        if ($key[0] === '!') {
            $separator = strpos($key, ':');
            if (substr($key, 1, $separator - 1) !== substr($uri, strrpos($uri, '/') + 1)) { return false; }
            $key = substr($key, $separator + 1);
        }
        return $key === '*' || $uri === $key || str_starts_with($uri, $key . '/');
    }

    private static function suffixKey(string $prefix, string $segment): string
    {
        // A normal prefix starts with '/' or equals '*'; a suffix key cannot collide.
        return '!' . $segment . ':' . $prefix;
    }

    /**
     * @param array<string, list<int>> $index
     * @return list<int>
     */
    public static function candidates(array $index, string $uri): array
    {
        $selected = $index['*'] ?? [];
        $merged = false;
        $suffix = '!' . substr($uri, strrpos($uri, '/') + 1) . ':';
        if (isset($index[$suffix . '*'])) { self::append($selected, $merged, $index[$suffix . '*']); }
        $prefix = $uri;
        while ($prefix !== '') {
            if (isset($index[$prefix])) { self::append($selected, $merged, $index[$prefix]); }
            if (isset($index[$suffix . $prefix])) { self::append($selected, $merged, $index[$suffix . $prefix]); }
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

    private static function append(array &$selected, bool &$merged, array $bucket): void
    {
        if ($bucket === []) { return; }
        if ($selected === []) { $selected = $bucket; return; }
        $selected = [...$selected, ...$bucket];
        $merged = true;
    }

    private function __construct() {}
}
