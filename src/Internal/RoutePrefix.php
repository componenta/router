<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Contract\CompilerInterface;

/** @internal Conservative literal path discriminator shared by both matching paths. */
final class RoutePrefix
{
    public static function forPattern(string $path, bool $standardSyntax, array $optional = []): string
    {
        if (!$standardSyntax) { return '*'; }
        $offset = strcspn($path, '[]{}<>:');
        $literal = substr($path, 0, $offset);
        $end = strrpos($literal, '/');
        if ($end === false || $end === 0) { return '*'; }

        // An omitted optional parameter consumes its preceding slash. If the
        // remaining template can join directly to this literal segment, only
        // the parent prefix is guaranteed to end at a request segment boundary.
        if ($optional !== [] && $end === $offset - 1
            && !self::hasGuaranteedBoundary($path, $offset, $optional)) {
            $end = strrpos(substr($literal, 0, $end), '/');
        }
        return $end === false || $end === 0 ? '*' : substr($literal, 0, $end);
    }

    /** Check the tail after a possibly omitted slash and optional placeholder. */
    private static function hasGuaranteedBoundary(string $path, int $offset, array $optional): bool
    {
        $marker = $path[$offset] ?? '';
        $close = match ($marker) {
            '{' => '}', '[' => ']', '<' => '>', ':' => null,
            default => '',
        };
        if ($close === '') { return true; }

        // A built-in compiler uses one syntax per template. Walk consecutive
        // optional placeholders whose slashes may all disappear; any required
        // placeholder or literal after a slash establishes a boundary.
        do {
            $nameOffset = $offset + 1 + (int) ($marker === '[' && ($path[$offset + 1] ?? '') === '?');
            $name = InlineConstraint::nameAt($path, $nameOffset);
            if ($name === null || !array_key_exists($name, $optional)) { return true; }
            try {
                if ($marker === ':') {
                    $end = InlineConstraint::colonParameter($path, $offset)['end'];
                } else {
                    $closing = InlineConstraint::bracketEnd($path, $offset, $close);
                    if ($closing === false) { return false; }
                    $end = $closing + 1;
                }
            } catch (\InvalidArgumentException) {
                // A marker belonging to a different syntax can be literal.
                // Prefer the conservative parent rather than reparsing it.
                return false;
            }
            if ($end === strlen($path)) { return true; }
            if ($path[$end] !== '/') { return false; }
            $offset = $end + 1;
        } while (($path[$offset] ?? '') === $marker);

        return true;
    }

    /** @param list<string> $names */
    public static function indexedKey(string $path, bool $standardSyntax, array $tokens, array $names, array $optional = []): string
    {
        $prefix = self::forPattern($path, $standardSyntax, $optional);
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

    /** @param array<string, list<int>> $index */
    public static function maxPrefixLength(array $index): int
    {
        $max = 0;
        foreach ($index as $key => $_) {
            $offset = $key[0] === '/' ? 0 : strrpos($key, ':');
            if ($offset === false) { continue; }
            if ($key[0] !== '/') { ++$offset; }
            if (($key[$offset] ?? '') === '/') {
                $max = max($max, strlen($key) - $offset);
            }
        }
        return $max;
    }

    /**
     * @param array<string, list<int>> $index
     * @return list<int>
     */
    public static function candidates(array $index, string $uri, int $maxPrefixLength): array
    {
        if ($index === []) { return []; }
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
        // No registered literal prefix can exceed this bound. Cut only at
        // a segment boundary; shorter prefixes and wildcard buckets still apply.
        $prefix = $uri;
        if (strlen($uri) > $maxPrefixLength) {
            $prefix = substr($uri, 0, $maxPrefixLength + 1);
            $prefix = substr($prefix, 0, strrpos($prefix, '/'));
        }
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
            $selected = array_keys($selected);
            sort($selected, SORT_NUMERIC);
        }
        return $selected;
    }

    private static function append(array &$selected, bool &$merged, array $bucket): void
    {
        if ($bucket === []) { return; }
        if ($selected === []) { $selected = $bucket; return; }
        // Convert to an ordinal set only when multiple non-empty buckets
        // contribute candidates. Merge into the set instead of repeatedly
        // copying the growing list; the single-bucket path stays unchanged.
        if (!$merged) {
            $selected = array_fill_keys($selected, true);
            $merged = true;
        }
        $selected += array_fill_keys($bucket, true);
    }

    private function __construct() {}
}
