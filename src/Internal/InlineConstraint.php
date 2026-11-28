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
    public static function findOutside(
        string $text,
        int $offset,
        string $delimiters,
        bool $last = false,
        bool $balanceBraces = true,
    ): int|false
    {
        $length = strlen($text);
        $stack = [];
        $extendedMore = false;
        $found = false;
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
                if (!$last) { return $i; }
                $found = $i;
                continue;
            }
            if ($char === '[') {
                $end = self::characterClassEnd($text, $i, $extendedMore);
                if ($end === false) { return false; }
                $i = $end;
                continue;
            }
            if ($char === '(') {
                $opaqueEnd = self::opaqueGroupEnd($text, $i);
                if ($opaqueEnd === false) { return false; }
                if ($opaqueEnd !== null) {
                    $i = $opaqueEnd;
                    continue;
                }
                $options = self::inlineOptions($text, $i, $extendedMore);
                if ($options !== null) {
                    [$end, $mode] = $options;
                    if ($text[$end] === ':') { $stack[] = [')', $extendedMore]; }
                    $extendedMore = $mode;
                    $i = $end;
                    continue;
                }
            }
            // A regex group already shields template delimiters. Braces
            // inside it may be literal PCRE characters, not a nested group:
            // an unmatched literal opening brace must not hide its closing ')'.
            // Bracket templates retain top-level brace handling to protect
            // their delimiters. Colon constraints end at a parenthesis instead.
            if ($char === '(' || ($balanceBraces && $char === '{' && ($stack === [] || end($stack)[0] !== ')'))) {
                $stack[] = [$char === '(' ? ')' : '}', $extendedMore];
            } elseif ($stack !== [] && end($stack)[0] === $char) {
                [, $previousMode] = array_pop($stack);
                if ($char === ')') { $extendedMore = $previousMode; }
            }
        }
        return $found;
    }

    /**
     * Recognize option settings, not other (?...) constructs. PCRE validates
     * the options; the lexer only needs the extended-more character-class mode.
     * @return array{int, bool}|null End of option header and new mode.
     */
    private static function inlineOptions(string $text, int $start, bool $extendedMore): ?array
    {
        if (($text[$start + 1] ?? '') !== '?') { return null; }
        $i = $start + 2;
        if (($text[$i] ?? '') === '^') {
            $extendedMore = false;
            ++$i;
        }
        $unset = false;
        $setsExtendedMore = false;
        for ($length = strlen($text); $i < $length; ++$i) {
            $option = $text[$i];
            if ($option === ')' || $option === ':') { return [$i, $extendedMore]; }
            if ($option === '-' && !$unset) {
                $unset = true;
                continue;
            }
            if ($option === 'x') {
                $more = ($text[$i + 1] ?? '') === 'x';
                if ($more) { ++$i; }
                // Within one option header, any positive xx enables extended
                // more even when another positive x follows. A separate x-only
                // header or a negative x/xx clears it.
                $setsExtendedMore = $setsExtendedMore || (!$unset && $more);
                $extendedMore = !$unset && $setsExtendedMore;
                // Further consecutive x characters still select xx.
                if ($more) {
                    while (($text[$i + 1] ?? '') === 'x') { ++$i; }
                }
                continue;
            }
            if ($option === 'a') {
                if (isset($text[$i + 1]) && str_contains('DSWPT', $text[$i + 1])) { ++$i; }
                continue;
            }
            if (!str_contains('imnsJUr', $option)) { return null; }
        }
        return null;
    }

    /**
     * Text arguments of PCRE control verbs and string callouts are not regex
     * source. Return null for ordinary groups, false for an unfinished payload.
     */
    private static function opaqueGroupEnd(string $text, int $start): int|false|null
    {
        if (substr_compare($text, '(?[', $start, 3) === 0) {
            return self::extendedClassEnd($text, $start + 3);
        }
        $kind = $text[$start + 1] ?? '';
        if ($kind === '*') {
            foreach (['(*:', '(*MARK:', '(*PRUNE:', '(*SKIP:', '(*THEN:', '(*COMMIT:', '(*ACCEPT:'] as $prefix) {
                if (substr_compare($text, $prefix, $start, strlen($prefix)) === 0) {
                    return strpos($text, ')', $start + strlen($prefix));
                }
            }
            return null;
        }
        if ($kind !== '?' || ($text[$start + 2] ?? '') !== 'C') { return null; }
        $delimiter = $text[$start + 3] ?? '';
        if (!in_array($delimiter, ["'", '"', '`', '^', '%', '#', '$', '{'], true)) { return null; }
        $close = $delimiter === '{' ? '}' : $delimiter;
        $offset = $start + 4;
        while (($end = strpos($text, $close, $offset)) !== false) {
            if (($text[$end + 1] ?? '') === $close) {
                $offset = $end + 2;
                continue;
            }
            return ($text[$end + 1] ?? '') === ')' ? $end + 1 : false;
        }
        return false;
    }

    /** Locate the end of a Perl extended character class (?[...]). */
    private static function extendedClassEnd(string $text, int $offset): int|false
    {
        // The outer brackets enclose a set expression, not an ordinary class.
        // Nested ordinary classes run in extended-more mode (space/tab ignored).
        for ($i = $offset, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ($char === '\\') {
                $escape = $text[$i + 1] ?? '';
                if ($escape === 'Q') {
                    $end = strpos($text, '\\E', $i + 2);
                    if ($end === false) { return false; }
                    $i = $end + 1;
                } else {
                    $i += $escape === 'c' ? 2 : 1;
                }
            } elseif ($char === '[') {
                $end = self::characterClassEnd($text, $i, true);
                if ($end === false) { return false; }
                $i = $end;
            } elseif ($char === ']') {
                return ($text[$i + 1] ?? '') === ')' ? $i + 1 : false;
            }
        }
        return false;
    }

    private static function characterClassEnd(string $text, int $start, bool $extendedMore = false): int|false
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
            } elseif ($extendedMore && ($char === ' ' || $char === "\t")) {
                continue;
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
            // Curly/angle syntax reserves a final top-level '?' as the
            // optional marker, not a character consumed by a PCRE escape.
            if (!$prefixOptional && str_ends_with($constraint, '?') && self::hasOptionalSuffix($constraint)) {
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

    private static function hasOptionalSuffix(string $constraint): bool
    {
        // Retain option scope across earlier quantifiers. Restarting the scan
        // at each question mark would forget a preceding (?xx) setting.
        return self::findOutside($constraint, 0, '?', last: true) === strlen($constraint) - 1;
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
            $close = self::findOutside($text, $end + 1, ')', balanceBraces: false);
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
