<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use InvalidArgumentException;

/** @internal Lexical boundaries for inline PCRE constraints, not a PCRE validator. */
final class InlineConstraint
{
    private const string NAME_START = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_';
    private const string NAME_CHARS = self::NAME_START . '0123456789';

    public static function nameAt(string $text, int $offset): ?string
    {
        if (!isset($text[$offset]) || !str_contains(self::NAME_START, $text[$offset])) { return null; }
        return substr($text, $offset, strspn($text, self::NAME_CHARS, $offset));
    }

    public static function isName(string $name): bool
    {
        return $name !== '' && self::nameAt($name, 0) === $name;
    }

    /** @return array{int, string}|null */
    public static function nextPlaceholder(string $text, int $offset = 0, string $markers = '{[<:'): ?array
    {
        $length = strlen($text);
        while ($offset < $length) {
            $start = $offset + strcspn($text, $markers, $offset);
            if ($start >= $length) { return null; }
            $marker = $text[$start];
            $offset = $start + 1;
            if ($marker === ':' && $start > 0 && $text[$start - 1] !== '/') { continue; }
            $nameStart = $offset + (int) ($marker === '[' && ($text[$offset] ?? '') === '?');
            if (self::nameAt($text, $nameStart) !== null) { return [$start, $marker]; }
        }
        return null;
    }

    /**
     * Locate a delimiter outside escaped characters, character classes and
     * balanced regex groups. PCRE itself validates the resulting expression.
     */
    public static function findOutside(string $text, int $offset, string $delimiters): int|false
    {
        $length = strlen($text);
        $stack = [];
        for ($i = $offset; $i < $length; ++$i) {
            $char = $text[$i];
            if ($char === '\\') {
                if (($text[$i + 1] ?? '') === 'Q') {
                    $end = strpos($text, '\\E', $i + 2);
                    if ($end === false) { return false; }
                    $i = $end + 1;
                } elseif (($text[$i + 1] ?? '') === 'c') {
                    // A PCRE control escape consumes its following character,
                    // even when that character is a template delimiter.
                    $i += 2;
                } elseif (in_array($text[$i + 1] ?? '', ['k', 'g'], true)
                    && ($text[$i + 2] ?? '') === '<') {
                    $end = strpos($text, '>', $i + 3);
                    if ($end === false) { return false; }
                    $i = $end;
                } else {
                    ++$i;
                }
                continue;
            }
            if ($stack === [] && str_contains($delimiters, $char)) {
                return $i;
            }
            if ($char === '[') {
                $end = self::characterClassEnd($text, $i);
                if ($end === false) { return false; }
                $i = $end;
                continue;
            }
            if ($char === '(' || $char === '{') {
                $stack[] = $char === '(' ? ')' : '}';
            } elseif ($stack !== [] && end($stack) === $char) {
                array_pop($stack);
            }
        }
        return false;
    }

    private static function characterClassEnd(string $text, int $start): int|false
    {
        $hasAtom = false;
        $mayNegate = true;
        for ($i = $start + 1, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ($char === '\\') {
                $escape = $text[$i + 1] ?? '';
                if ($escape === 'Q') {
                    $end = strpos($text, '\\E', $i + 2);
                    if ($end === false) { return false; }
                    // Empty quoting does not consume the first class atom.
                    if ($end !== $i + 2) { $hasAtom = true; $mayNegate = false; }
                    $i = $end + 1;
                    continue;
                }
                if ($escape === 'E') { ++$i; continue; }
                $i += $escape === 'c' ? 2 : 1;
            } elseif ($char === '^' && $mayNegate) {
                $mayNegate = false;
                continue;
            } elseif ($char === ']' && $hasAtom) {
                return $i;
            } elseif ($char === '[' && in_array($text[$i + 1] ?? '', [':', '.', '='], true)) {
                $end = strpos($text, $text[$i + 1] . ']', $i + 2);
                if ($end === false) { return false; }
                $i = $end + 1;
            }
            // The first unescaped ] is literal (also after a negating ^).
            $hasAtom = true;
            $mayNegate = false;
        }
        return false;
    }

    public static function bracketEnd(string $text, int $start, string $close): int|false
    {
        // The name and default are not regex source.
        $nameStart = $start + 1 + (int) (($text[$start + 1] ?? '') === '?');
        $name = self::nameAt($text, $nameStart);
        if ($name === null) { return strpos($text, $close, $start + 1); }
        $offset = $nameStart + strlen($name);
        if (($text[$offset] ?? '') !== ':') {
            return strpos($text, $close, $offset);
        }
        $end = self::findOutside($text, $offset + 1, $close . '=');
        if ($end === false || $text[$end] === $close) { return $end; }
        return strpos($text, $close, $end + 1);
    }

    /** @return array{name: string, constraint: ?string, optional: bool, default: ?string} */
    public static function bracketParameter(string $text, bool $prefixOptional): array
    {
        $empty = ['name' => '', 'constraint' => null, 'optional' => false, 'default' => null];
        $optional = $prefixOptional && str_starts_with($text, '?');
        $nameStart = (int) $optional;
        $name = self::nameAt($text, $nameStart);
        if ($name === null) { return $empty; }
        $rest = substr($text, $nameStart + strlen($name));
        $default = $constraint = null;
        if (str_starts_with($rest, ':')) {
            $constraint = substr($rest, 1);
            $equal = self::findOutside($constraint, 0, '=');
            if ($equal !== false) {
                $default = substr($constraint, $equal + 1);
                $constraint = substr($constraint, 0, $equal);
            }
            // Curly/angle syntax reserves an unescaped final '?' as the
            // optional marker. Use explicit tokens for a trailing lazy quantifier.
            if (!$prefixOptional && str_ends_with($constraint, '?') && !self::escapedAt($constraint, strlen($constraint) - 1)) {
                $optional = true;
                $constraint = substr($constraint, 0, -1);
            }
            if ($constraint === '') {
                throw new InvalidArgumentException('Empty inline constraint for route parameter: ' . $name);
            }
        } elseif (str_starts_with($rest, '=')) {
            $default = substr($rest, 1);
        } elseif (!$prefixOptional && ($rest === '?' || str_starts_with($rest, '?='))) {
            $optional = true;
            if (str_starts_with($rest, '?=')) { $default = substr($rest, 2); }
        } elseif ($rest !== '') {
            throw new InvalidArgumentException('Malformed route parameter: ' . $name);
        }
        return ['name' => $name, 'constraint' => $constraint, 'optional' => $optional || $default !== null, 'default' => $default];
    }

    private static function escapedAt(string $text, int $offset): bool
    {
        $slashes = 0;
        while ($offset > 0 && $text[--$offset] === '\\') { ++$slashes; }
        return $slashes % 2 !== 0;
    }

    /**
     * Read one colon placeholder. A colon starts a parameter only at the
     * beginning of a path or immediately after a slash.
     * @return array{name: string, constraint: ?string, optional: bool, default: ?string, end: int}
     */
    public static function colonParameter(string $text, int $start): array
    {
        $name = self::nameAt($text, $start + 1);
        if (($text[$start] ?? '') !== ':' || $name === null) {
            throw new InvalidArgumentException('Malformed colon route parameter.');
        }
        $end = $start + 1 + strlen($name);
        $constraint = $default = null;
        $optional = false;
        if (($text[$end] ?? '') === '(') {
            $close = self::findOutside($text, $end + 1, ')');
            if ($close === false || $close === $end + 1) {
                throw new InvalidArgumentException('Invalid inline constraint for route parameter: ' . $name);
            }
            $constraint = substr($text, $end + 1, $close - $end - 1);
            $end = $close + 1;
        }
        if (($text[$end] ?? '') === '?') {
            $optional = true;
            ++$end;
        }
        if (($text[$end] ?? '') === '=') {
            $startDefault = ++$end;
            $end = strpos($text, '/', $end);
            if ($end === false) { $end = strlen($text); }
            $default = substr($text, $startDefault, $end - $startDefault);
        }
        return ['name' => $name, 'constraint' => $constraint, 'optional' => $optional || $default !== null, 'default' => $default, 'end' => $end];
    }

    private function __construct() {}
}
