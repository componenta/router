<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Internal\InlineConstraint;
use Componenta\Http\Router\Internal\PathParameter;
use InvalidArgumentException;

/** Colon placeholders, e.g. /items/:id(\d+) or /pages/:page?=1. */
final class ColonSyntax implements SyntaxParserInterface
{
    private const string DEFAULT_PATTERN = '[^/]+';

    public function canParse(string $pattern): bool
    {
        return InlineConstraint::nextPlaceholder($pattern, 0, ':') !== null;
    }

    /** @return iterable<array{name: string, constraint: ?string, optional: bool, default: ?string, end: int, start: int}> */
    private function parameters(string $pattern): iterable
    {
        $offset = 0;
        $seen = [];
        while (($placeholder = InlineConstraint::nextPlaceholder($pattern, $offset, ':')) !== null) {
            [$start] = $placeholder;
            $parameter = InlineConstraint::colonParameter($pattern, $start);
            $name = $parameter['name'];
            if (isset($seen[$name])) {
                throw new InvalidArgumentException('Duplicate route parameter: ' . $name);
            }
            $seen[$name] = true;
            $offset = $parameter['end'];
            yield $parameter + ['start' => $start];
        }
    }

    public function parse(string $pattern): array
    {
        $parameters = $optionalParameters = $tokens = $defaults = [];
        foreach ($this->parameters($pattern) as $parameter) {
            ['name' => $name, 'constraint' => $constraint, 'optional' => $optional, 'default' => $default] = $parameter;
            if ($optional) {
                $optionalParameters[$name] = $constraint;
                if ($default !== null) { $defaults[$name] = $default; }
            } else {
                $parameters[$name] = $constraint;
            }
            if ($constraint !== null) { $tokens[$name] = $constraint; }
        }
        return compact('parameters', 'optionalParameters', 'tokens', 'defaults');
    }

    public function hasParameter(string $segment): bool
    {
        return $this->canParse($segment);
    }

    public function toRegex(string $pattern, array $tokens): string
    {
        $result = '';
        $offset = 0;
        foreach ($this->parameters($pattern) as $parameter) {
            $name = $parameter['name'];
            $constraint = $tokens[$name] ?? $parameter['constraint'] ?? self::DEFAULT_PATTERN;
            $static = substr($pattern, $offset, $parameter['start'] - $offset);
            $separator = '';
            if ($parameter['optional'] && str_ends_with($static, '/')) {
                $static = substr($static, 0, -1);
                $separator = '/';
            }
            $capture = '(?P<' . $name . '>' . $constraint . ')';
            $result .= preg_quote($static, '#');
            $result .= $parameter['optional'] ? '(?:' . $separator . $capture . ')?' : $capture;
            $offset = $parameter['end'];
        }
        return $result . preg_quote(substr($pattern, $offset), '#');
    }

    public function normalize(string $pattern): string
    {
        $result = '';
        $offset = 0;
        foreach ($this->parameters($pattern) as $parameter) {
            $result .= substr($pattern, $offset, $parameter['start'] - $offset);
            $result .= ':' . $parameter['name'] . ($parameter['optional'] ? '?' : '');
            $offset = $parameter['end'];
        }
        return $result . substr($pattern, $offset);
    }

    public function buildPath(string $pattern, array $params, array $tokens, array $optional, string $routeName): string
    {
        $result = '';
        $expected = [];
        $offset = 0;
        foreach ($this->parameters($pattern) as $parameter) {
            $name = $parameter['name'];
            $static = substr($pattern, $offset, $parameter['start'] - $offset);
            if (!isset($params[$name])) {
                $expected[$name] = null;
                if (!$parameter['optional'] && !array_key_exists($name, $optional)) {
                    throw new InvalidArgumentException("Missing required parameter '$name' for route '$routeName'");
                }
                if (str_ends_with($static, '/')) { $static = substr($static, 0, -1); }
                $result .= $static;
            } else {
                $expected[$name] = PathParameter::encode($params[$name], $name);
                $result .= $static . $expected[$name];
            }
            $offset = $parameter['end'];
        }
        $path = PathParameter::finish($result . substr($pattern, $offset));
        return $expected === [] ? $path : PathParameter::verify($path, $this->toRegex($pattern, $tokens), $expected, $routeName);
    }
}
