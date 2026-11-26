<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Contract\SyntaxParserInterface;
use InvalidArgumentException;
use Componenta\Http\Router\Internal\PathParameter;

/**
 * Base class for bracket-based parameter syntaxes.
 *
 * Provides common implementation for syntaxes that use paired delimiters
 * like {}, <>, []. Subclasses define the specific delimiters and parsing rules.
 */
abstract class AbstractBracketSyntax implements SyntaxParserInterface
{
    protected const string DEFAULT_PATTERN = '[^/]+';

    /**
     * Opening bracket character.
     */
    abstract protected function openChar(): string;

    /**
     * Closing bracket character.
     */
    abstract protected function closeChar(): string;

    /**
     * Find position of closing bracket from given start position.
     *
     * @param string $pattern The pattern to search in
     * @param int $start Position of opening bracket
     * @return int|false Position of closing bracket or false if not found
     */
    abstract protected function findClosing(string $pattern, int $start): int|false;

    /**
     * Parse parameter string content (between brackets).
     *
     * @param string $param Content between brackets
     * @return array{name: string, constraint: string|null, optional: bool, default: string|null}
     */
    abstract protected function parseParam(string $param): array;

    /**
     * Format parameter for normalized output.
     *
     * @param array{name: string, optional: bool} $parsed Parsed parameter info
     * @return string Formatted parameter string with brackets
     */
    abstract protected function formatNormalized(array $parsed): string;

    /**
     * Check if parsed parameter is valid.
     *
     * Valid parameter has non-empty name starting with letter or underscore.
     */
    protected function isValidParam(array $parsed): bool
    {
        $name = $parsed['name'];
        return $name !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) === 1;
    }

    public function parse(string $pattern): array
    {
        $parameters = [];
        $optionalParameters = [];
        $tokens = [];
        $defaults = [];

        $this->walkParams($pattern, function (array $parsed) use (&$parameters, &$optionalParameters, &$tokens, &$defaults): void {
            $name = $parsed['name'];
            if (array_key_exists($name, $parameters) || array_key_exists($name, $optionalParameters)) {
                throw new InvalidArgumentException('Duplicate route parameter: ' . $name);
            }
            $constraint = $parsed['constraint'];
            $optional = $parsed['optional'];
            $default = $parsed['default'];

            if ($optional) {
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
        });

        return [
            'parameters' => $parameters,
            'optionalParameters' => $optionalParameters,
            'tokens' => $tokens,
            'defaults' => $defaults,
        ];
    }

    public function hasParameter(string $segment): bool
    {
        $parsed = $this->parse($segment);
        return $parsed['parameters'] !== [] || $parsed['optionalParameters'] !== [];
    }

    public function toRegex(string $pattern, array $tokens): string
    {
        $result = '';
        $open = $this->openChar();
        $pos = 0;
        $len = strlen($pattern);

        while ($pos < $len) {
            $start = strpos($pattern, $open, $pos);

            if ($start === false) {
                $result .= preg_quote(substr($pattern, $pos), '#');
                break;
            }

            $end = $this->findClosing($pattern, $start);
            if ($end === false && $this->canParse(substr($pattern, $start))) {
                throw new InvalidArgumentException('Unclosed route parameter in: ' . $pattern);
            }
            if ($end === false) {
                $result .= preg_quote(substr($pattern, $pos), '#');
                break;
            }

            $static = substr($pattern, $pos, $start - $pos);
            $paramStr = substr($pattern, $start + 1, $end - $start - 1);
            $parsed = $this->parseParam($paramStr);

            if (!$this->isValidParam($parsed)) {
                $result .= preg_quote($static, '#');
                $result .= preg_quote($this->openChar() . $paramStr . $this->closeChar(), '#');
            } else {
                $constraint = $tokens[$parsed['name']] ?? $parsed['constraint'] ?? self::DEFAULT_PATTERN;

                // Strip trailing slash from static part for optional parameters -
                // it's already included in the optional group (?:/...)
                $separator = '';
                if ($parsed['optional'] && str_ends_with($static, '/')) {
                    $static = substr($static, 0, -1);
                    $separator = '/';
                }

                $result .= preg_quote($static, '#');
                $result .= $parsed['optional']
                    ? '(?:' . $separator . '(?P<' . $parsed['name'] . '>' . $constraint . '))?'
                    : '(?P<' . $parsed['name'] . '>' . $constraint . ')';
            }

            $pos = $end + 1;
        }

        return $result;
    }

    public function normalize(string $pattern): string
    {
        return $this->transform($pattern, function (array $parsed, string $paramStr): string {
            if (!$this->isValidParam($parsed)) {
                return $this->openChar() . $paramStr . $this->closeChar();
            }
            return $this->formatNormalized($parsed);
        }, false);
    }

    public function buildPath(string $pattern, array $params, array $tokens, array $optional, string $routeName): string
    {
        $result = '';
        $expected = [];
        $open = $this->openChar();
        $pos = 0;
        $len = strlen($pattern);

        while ($pos < $len) {
            $start = strpos($pattern, $open, $pos);

            if ($start === false) {
                $result .= substr($pattern, $pos);
                break;
            }

            $end = $this->findClosing($pattern, $start);
            if ($end === false && $this->canParse(substr($pattern, $start))) {
                throw new InvalidArgumentException('Unclosed route parameter in: ' . $pattern);
            }
            if ($end === false) {
                $result .= substr($pattern, $pos);
                break;
            }

            $static = substr($pattern, $pos, $start - $pos);
            $paramStr = substr($pattern, $start + 1, $end - $start - 1);
            $parsed = $this->parseParam($paramStr);

            if (!$this->isValidParam($parsed)) {
                $result .= $static;
                $result .= $this->openChar() . $paramStr . $this->closeChar();
            } else {
                $name = $parsed['name'];
                $isOptional = $parsed['optional'] || array_key_exists($name, $optional);

                if (array_key_exists($name, $expected)) {
                    throw new InvalidArgumentException('Duplicate route parameter: ' . $name);
                }
                if (!isset($params[$name])) {
                    $expected[$name] = null;
                    if ($isOptional) {
                        // Strip trailing slash - the parameter (with its slash) is omitted
                        if (str_ends_with($static, '/')) {
                            $static = substr($static, 0, -1);
                        }
                        $result .= $static;
                    } else {
                        throw new InvalidArgumentException("Missing required parameter '$name' for route '$routeName'");
                    }
                } else {
                    $expected[$name] = PathParameter::encode($params[$name], $name);
                    $result .= $static . $expected[$name];
                }
            }

            $pos = $end + 1;
        }

        $path = PathParameter::finish($result);
        return $expected === [] ? $path : PathParameter::verify($path, $this->toRegex($pattern, $tokens), $expected, $routeName);
    }

    /**
     * Walk through all valid parameters in pattern.
     *
     * @param string $pattern Route pattern
     * @param callable(array): void $callback Callback receiving parsed parameter
     */
    protected function walkParams(string $pattern, callable $callback): void
    {
        $open = $this->openChar();
        $pos = 0;
        $len = strlen($pattern);

        while ($pos < $len) {
            $start = strpos($pattern, $open, $pos);
            if ($start === false) {
                break;
            }

            $end = $this->findClosing($pattern, $start);
            if ($end === false && $this->canParse(substr($pattern, $start))) {
                throw new InvalidArgumentException('Unclosed route parameter in: ' . $pattern);
            }
            if ($end === false) {
                break;
            }

            $parsed = $this->parseParam(substr($pattern, $start + 1, $end - $start - 1));

            if ($this->isValidParam($parsed)) {
                $callback($parsed);
            }

            $pos = $end + 1;
        }
    }

    /**
     * Transform pattern by applying callback to each parameter.
     *
     * @param string $pattern Route pattern
     * @param callable(array, string): ?string $callback Returns replacement or null to skip
     * @param bool $quoteStatic Whether to preg_quote static parts
     * @return string Transformed pattern
     */
    protected function transform(string $pattern, callable $callback, bool $quoteStatic): string
    {
        $result = '';
        $open = $this->openChar();
        $pos = 0;
        $len = strlen($pattern);

        while ($pos < $len) {
            $start = strpos($pattern, $open, $pos);

            if ($start === false) {
                $result .= $quoteStatic ? preg_quote(substr($pattern, $pos), '#') : substr($pattern, $pos);
                break;
            }

            $static = substr($pattern, $pos, $start - $pos);
            $result .= $quoteStatic ? preg_quote($static, '#') : $static;

            $end = $this->findClosing($pattern, $start);
            if ($end === false && $this->canParse(substr($pattern, $start))) {
                throw new InvalidArgumentException('Unclosed route parameter in: ' . $pattern);
            }
            if ($end === false) {
                $remaining = substr($pattern, $start);
                $result .= $quoteStatic ? preg_quote($remaining, '#') : $remaining;
                break;
            }

            $paramStr = substr($pattern, $start + 1, $end - $start - 1);
            $replacement = $callback($this->parseParam($paramStr), $paramStr);

            if ($replacement !== null) {
                $result .= $replacement;
            }

            $pos = $end + 1;
        }

        return $result;
    }

    /**
     * Clean up double slashes in result.
     */
    protected function cleanSlashes(string $path): string
    {
        return str_contains($path, '//') ? preg_replace('#/+#', '/', $path) : $path;
    }
}