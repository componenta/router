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

    /** Derive a known-valid expression for one required whole-segment parameter. */
    public static function simpleExpression(string $path, array $tokens, array $names): ?string
    {
        if (count($names) !== 1) { return null; }
        $name = $names[0];
        $offset = strcspn($path, '[]{}<>:');
        if ($offset >= strlen($path) || $offset === 0 || $path[$offset - 1] !== '/') { return null; }
        $placeholder = match ($path[$offset]) {
            '{' => '{' . $name . '}',
            '[' => '[' . $name . ']',
            '<' => '<' . $name . '>',
            ':' => ':' . $name,
            default => '',
        };
        if ($placeholder === '' || !str_starts_with(substr($path, $offset), $placeholder)) { return null; }
        $suffix = substr($path, $offset + strlen($placeholder));
        if (($suffix !== '' && $suffix[0] !== '/') || strcspn($suffix, '[]{}<>:') !== strlen($suffix)) { return null; }
        if (!InlineConstraint::isName($name) || strlen($name) > 32) { return null; }
        $token = $tokens[$name] ?? '[^/]+';
        if ($token !== '[^/]+' && !in_array($token, \Componenta\Http\Router\Contract\CompilerInterface::DEFAULT_PATTERNS, true)) { return null; }
        return self::anchored(preg_quote(substr($path, 0, $offset), '#') . '(?P<' . $name . '>' . $token . ')' . preg_quote($suffix, '#'));
    }

    public static function match(string $regex, string $subject, ?array &$matches = null, bool $protectMark = false): bool
    {
        // Do not confuse an invalid expression or an exhausted PCRE limit with a miss.
        $flags = PREG_UNMATCHED_AS_NULL | ($protectMark ? PREG_OFFSET_CAPTURE : 0);
        try {
            $result = @preg_match($regex, $subject, $matches, $flags);
        } catch (\Throwable $error) {
            // A user-defined error handler can throw even for suppressed PCRE
            // warnings. Keep the same domain boundary without installing an
            // error handler on every successful route match.
            throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg(), previous: $error);
        }
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
        // Syntax validation must not depend on the cost of matching an empty
        // subject. PHP reports compile errors separately from execution limits.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            if (preg_match($regex, '') === false && !in_array(preg_last_error(), [
                PREG_BACKTRACK_LIMIT_ERROR,
                PREG_RECURSION_LIMIT_ERROR,
                PREG_JIT_STACKLIMIT_ERROR,
            ], true)) {
                throw new RoutePatternException('Route expression failed: ' . preg_last_error_msg());
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A route parameter must identify a single PCRE capture. Duplicate-name
     * mode can otherwise silently return the value of a nested capture.
     *
     * @param list<string> $names
     */
    public static function assertUniqueParameterCaptures(string $regex, array $names): void
    {
        // Built-in templates allocate distinct capture slots. Without J, PCRE
        // rejects duplicate names assigned to distinct slots itself.
        if (!str_contains($regex, 'J')) { return; }
        $validated = false;
        foreach ($names as $name) {
            $markers = ['(?P<' . $name . '>', '(?<' . $name . '>', "(?'" . $name . "'"];
            if (array_sum(array_map(static fn (string $marker): int => substr_count($regex, $marker), $markers)) < 2) { continue; }
            if (!$validated) {
                self::validate($regex);
                $validated = true;
            }
            $captures = 0;
            foreach ($markers as $marker) {
                $offset = 0;
                while (($start = strpos($regex, $marker, $offset)) !== false) {
                    // Let PCRE distinguish actual capture declarations from
                    // quoted text, character classes and control verb labels.
                    // A digit is not a valid initial character of a group name.
                    $probe = $regex;
                    $probe[$start + strlen($marker) - strlen($name) - 1] = '0';
                    try {
                        self::validate($probe);
                    } catch (RoutePatternException) {
                        if (++$captures > 1) {
                            throw new RoutePatternException('Duplicate PCRE capture for route parameter: ' . $name);
                        }
                    }
                    $offset = $start + strlen($marker);
                }
            }
        }
    }

    /** Validate a standalone token without letting its enclosing group repair it. */
    public static function validateFragment(string $pattern): void
    {
        self::validate('#' . $pattern . '#');
        self::validate(self::anchored($pattern));
    }

    /** Validate a complete expression and recognize the omitted optional root. */
    public static function withOptionalRoot(string $expression): string
    {
        return self::probe($expression) ? '(?:' . $expression . '|/)' : $expression;
    }

    /**
     * Compilation has a fixed, bounded budget independent of request-time PCRE
     * settings. The interpreted probe runs only on the empty subject.
     */
    private static function probe(string $expression): bool
    {
        $restore = [];
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            foreach (['pcre.backtrack_limit' => '1000000', 'pcre.recursion_limit' => '100000'] as $key => $limit) {
                $previous = ini_get($key);
                if ($previous === $limit) { continue; }
                if ($previous === false || ini_set($key, $limit) === false) {
                    throw new RoutePatternException('Cannot configure the route compilation PCRE budget.');
                }
                $restore[$key] = $previous;
            }
            // Avoid a different JIT stack or optimizer changing the build's
            // decision. Runtime expressions retain their original spelling.
            return self::match('#(*NO_JIT)\A(?:' . $expression . ')\z#', '');
        } finally {
            foreach ($restore as $key => $value) { ini_set($key, $value); }
            restore_error_handler();
        }
    }

    private function __construct() {}
}
