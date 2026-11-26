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
        $lastSlash = strrpos($path, '/');
        $segment = substr($path, $lastSlash + 1);
        if (!self::isLiteralSegment($segment)) {
            if ($lastSlash === ($prefix === '*' ? 0 : strlen($prefix))) { return $prefix; }
            return self::positionalKey(explode('/', substr($path, 1)), $prefix, $tokens, $names);
        }
        $parts = explode('/', substr($path, 1));
        // Only whole literal segments in simple built-in templates and known
        // safe token expressions qualify. Uncertain expressions retain the prefix-only path.
        foreach ($parts as $part) {
            if ($part === '') { return $prefix; }
            if (self::isLiteralSegment($part)) { continue; }
            $name = self::parameterName($part);
            if (!InlineConstraint::isName($name)) { return $prefix; }
        }
        foreach ($names as $name) {
            $token = $tokens[$name] ?? '[^/]+';
            if ($token !== '[^/]+' && !in_array($token, CompilerInterface::DEFAULT_PATTERNS, true)) { return $prefix; }
        }
        return self::suffixKey($prefix, $segment);
    }

    private static function isLiteralSegment(string $part): bool
    {
        return $part !== '' && strspn($part, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._~-') === strlen($part);
    }

    private static function parameterName(string $part): string
    {
        return match ($part[0]) {
            '{' => str_ends_with($part, '}') ? self::withoutOptionalSuffix(substr($part, 1, -1)) : '',
            '[' => str_ends_with($part, ']') ? (str_starts_with($part, '[?') ? substr($part, 2, -1) : substr($part, 1, -1)) : '',
            '<' => str_ends_with($part, '>') ? self::withoutOptionalSuffix(substr($part, 1, -1)) : '',
            ':' => self::withoutOptionalSuffix(substr($part, 1)),
            default => '',
        };
    }

    /** Fixed-width, required single-segment parameters allow positional literal discrimination. */
    private static function positionalKey(array $parts, string $prefix, array $tokens, array $names): string
    {
        $seen = false;
        $literal = null;
        $parameters = [];
        foreach ($parts as $offset => $part) {
            if ($part === '') { return $prefix; }
            if (self::isLiteralSegment($part)) {
                if ($seen) { $literal = [$offset, $part]; }
                continue;
            }
            // Every placeholder is required and consists of exactly one segment.
            if (str_contains($part, '?')) { return $prefix; }
            $name = self::parameterName($part);
            if (!InlineConstraint::isName($name) || !in_array($name, $names, true)) { return $prefix; }
            $token = $tokens[$name] ?? '[^/]+';
            if ($token === '.+' || ($token !== '[^/]+' && !in_array($token, CompilerInterface::DEFAULT_PATTERNS, true))) { return $prefix; }
            $parameters[] = $name;
            $seen = true;
        }
        if ($literal === null || count($parameters) !== count($names) || array_diff($parameters, $names) !== []) { return $prefix; }
        return '@' . count($parts) . ':' . $literal[0] . ':' . $literal[1] . ':' . $prefix;
    }

    /** @param array<string, list<int>> $index */
    public static function add(array &$index, string $key, int $ordinal): void
    {
        if ($key[0] === '@') {
            // Empty metadata buckets signal whether positional lookup is needed
            // for this method and for the request's exact segment count.
            $index['@'] ??= [];
            $index[substr($key, 0, strpos($key, ':'))] ??= [];
        }
        $index[$key][] = $ordinal;
    }

    private static function withoutOptionalSuffix(string $value): string
    {
        return str_ends_with($value, '?') ? substr($value, 0, -1) : $value;
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
        $positions = [];
        if (isset($index['@'])) {
            $count = substr_count($uri, '/');
            if (isset($index['@' . $count])) {
                foreach (explode('/', substr($uri, 1)) as $offset => $segment) {
                    if (!self::isLiteralSegment($segment)) { continue; }
                    $positions[] = '@' . $count . ':' . $offset . ':' . $segment . ':';
                }
                foreach ($positions as $position) {
                    if (isset($index[$position . '*'])) { self::append($selected, $merged, $index[$position . '*']); }
                }
            }
        }
        $prefix = $uri;
        while ($prefix !== '') {
            if (isset($index[$prefix])) { self::append($selected, $merged, $index[$prefix]); }
            if (isset($index[$suffix . $prefix])) { self::append($selected, $merged, $index[$suffix . $prefix]); }
            foreach ($positions as $position) {
                if (isset($index[$position . $prefix])) { self::append($selected, $merged, $index[$position . $prefix]); }
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

    private static function append(array &$selected, bool &$merged, array $bucket): void
    {
        if ($bucket === []) { return; }
        if ($selected === []) { $selected = $bucket; return; }
        $selected = [...$selected, ...$bucket];
        $merged = true;
    }

    private function __construct() {}
}
