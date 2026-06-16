<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Contract\SyntaxParserInterface;
use InvalidArgumentException;

/**
 * Colon parameter syntax (Express.js/FastRoute style)
 *
 * Supports:
 * - :id - required parameter
 * - :id(\d+) - required with pattern
 * - :id? - optional parameter
 * - :id(\d+)? - optional with pattern
 * - :id=5 - with default (implies optional)
 * - :id(\d+)=5 - with pattern and default
 */
final class ColonSyntax implements SyntaxParserInterface
{
    // :name or :name(\d+) or :name? or :name(\d+)? or :name=default or :name(\d+)=default
    private const string PARAM_REGEX = '/:([a-zA-Z_][a-zA-Z0-9_]*)(?:\(([^)]+)\))?(\?)?(?:=([^\/]*))?/';
    private const string DEFAULT_PATTERN = '[^/]+';

    public function canParse(string $pattern): bool
    {
        // Look for :name after / or at start, but not :// (URLs) or :: (namespaces)
        return (bool) preg_match('#(?<=/):[a-zA-Z_]|^:[a-zA-Z_]#', $pattern);
    }

    public function parse(string $pattern): array
    {
        $parameters = [];
        $optionalParameters = [];
        $tokens = [];
        $defaults = [];

        if (!preg_match_all(self::PARAM_REGEX, $pattern, $matches, PREG_SET_ORDER)) {
            return [
                'parameters' => $parameters,
                'optionalParameters' => $optionalParameters,
                'tokens' => $tokens,
                'defaults' => $defaults,
            ];
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $constraint = !empty($match[2]) ? $match[2] : null;
            $optional = ($match[3] ?? '') === '?';
            $default = isset($match[4]) && $match[4] !== '' ? $match[4] : null;

            // Default value implies optional
            if ($optional || $default !== null) {
                $optionalParameters[$name] = $constraint;
                if ($default !== null) {
                    $defaults[$name] = $default;
                }
            } else {
                $parameters[$name] = $constraint;
            }

            if ($constraint !== null) {
                $tokens[$name] = $constraint;
            }
        }

        return [
            'parameters' => $parameters,
            'optionalParameters' => $optionalParameters,
            'tokens' => $tokens,
            'defaults' => $defaults,
        ];
    }

    public function hasParameter(string $segment): bool
    {
        return (bool) preg_match(self::PARAM_REGEX, $segment);
    }

    public function toRegex(string $pattern, array $tokens): string
    {
        $regex = '';
        $lastPos = 0;

        if (preg_match_all(self::PARAM_REGEX, $pattern, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $fullMatch = $match[0][0];
                $offset = $match[0][1];

                $name = $match[1][0];
                $inlineConstraint = !empty($match[2][0]) ? $match[2][0] : null;
                $optional = ($match[3][0] ?? '') === '?';
                $hasDefault = isset($match[4][0]) && $match[4][0] !== '';

                $constraint = $tokens[$name] ?? $inlineConstraint ?? self::DEFAULT_PATTERN;

                // Escape static part before this parameter
                $staticPart = substr($pattern, $lastPos, $offset - $lastPos);

                // Strip trailing slash from static part for optional parameters -
                // it's already included in the optional group (?:/...)
                if (($optional || $hasDefault) && str_ends_with($staticPart, '/')) {
                    $staticPart = substr($staticPart, 0, -1);
                }

                $regex .= preg_quote($staticPart, '#');

                // Default value implies optional
                if ($optional || $hasDefault) {
                    $regex .= '(?:/(?P<' . $name . '>' . $constraint . '))?';
                } else {
                    $regex .= '(?P<' . $name . '>' . $constraint . ')';
                }

                $lastPos = (int) $offset + strlen($fullMatch);
            }
        }

        $regex .= preg_quote(substr($pattern, $lastPos), '#');

        return $regex;
    }

    public function normalize(string $pattern): string
    {
        return preg_replace_callback(
            self::PARAM_REGEX,
            static function (array $match): string {
                $name = $match[1];
                $optional = ($match[3] ?? '') === '?';
                $hasDefault = isset($match[4]) && $match[4] !== '';
                // Default implies optional
                return ($optional || $hasDefault) ? ':' . $name . '?' : ':' . $name;
            },
            $pattern
        );
    }

    public function buildPath(string $pattern, array $params, array $tokens, array $optional, string $routeName): string
    {
        $result = '';
        $lastPos = 0;

        if (preg_match_all(self::PARAM_REGEX, $pattern, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $fullMatch = $match[0][0];
                $offset = $match[0][1];

                $name = $match[1][0];
                $hasDefault = isset($match[4][0]) && $match[4][0] !== '';
                $isOptional = ($match[3][0] ?? '') === '?' || $hasDefault || isset($optional[$name]);

                $staticPart = substr($pattern, $lastPos, $offset - $lastPos);

                if (!isset($params[$name])) {
                    if ($isOptional) {
                        // Strip trailing slash - the parameter (with its slash) is omitted
                        if (str_ends_with($staticPart, '/')) {
                            $staticPart = substr($staticPart, 0, -1);
                        }
                        $result .= $staticPart;
                    } else {
                        throw new InvalidArgumentException("Missing required parameter '$name' for route '$routeName'");
                    }
                } else {
                    $value = (string) $params[$name];

                    if (isset($tokens[$name]) && !preg_match('#^' . $tokens[$name] . '$#', $value)) {
                        throw new InvalidArgumentException("Parameter '$name' value '$value' does not match pattern");
                    }

                    $result .= $staticPart;
                    $result .= $isOptional ? '/' . $value : $value;
                }

                $lastPos = (int) $offset + strlen($fullMatch);
            }
        }

        $result .= substr($pattern, $lastPos);

        return str_contains($result, '//') ? preg_replace('#/+#', '/', $result) : $result;
    }
}
