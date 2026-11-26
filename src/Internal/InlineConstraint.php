<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use InvalidArgumentException;

/** @internal Lexical boundaries for inline PCRE constraints, not a PCRE validator. */
final class InlineConstraint
{
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
        $i = $start + 1;
        if (($text[$i] ?? '') === '^') { ++$i; }
        if (($text[$i] ?? '') === ']') { ++$i; }
        for ($length = strlen($text); $i < $length; ++$i) {
            if ($text[$i] === '\\') {
                ++$i;
                continue;
            }
            if ($text[$i] === '[' && in_array($text[$i + 1] ?? '', [':', '.', '='], true)) {
                $end = strpos($text, $text[$i + 1] . ']', $i + 2);
                if ($end === false) { return false; }
                $i = $end + 1;
                continue;
            }
            if ($text[$i] === ']') { return $i; }
        }
        return false;
    }

    public static function bracketEnd(string $text, int $start, string $close): int|false
    {
        // The name and default are not regex source.
        if (!preg_match('/\G\??[A-Za-z_][A-Za-z0-9_]*/', $text, $match, 0, $start + 1)) {
            return strpos($text, $close, $start + 1);
        }
        $offset = $start + 1 + strlen($match[0]);
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
        $namePattern = $prefixOptional ? '/\A(\??)([A-Za-z_][A-Za-z0-9_]*)(.*)\z/s' : '/\A()([A-Za-z_][A-Za-z0-9_]*)(.*)\z/s';
        if (!preg_match($namePattern, $text, $match)) { return $empty; }
        [, $marker, $name, $rest] = $match;
        $optional = $marker === '?';
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
        if (!preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $text, $match, 0, $start)) {
            throw new InvalidArgumentException('Malformed colon route parameter.');
        }
        $name = $match[1];
        $end = $start + strlen($match[0]);
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
