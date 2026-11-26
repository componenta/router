<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Internal\InlineConstraint;

/**
 * Square bracket parameter syntax parser.
 *
 * Supports:
 * - [id] - required parameter
 * - [id:\d+] - required with pattern
 * - [?id] - optional parameter
 * - [?id:\d+] - optional with pattern
 * - [?id=default] - optional with default
 * - [?id:\d+=5] - optional with pattern and default
 */
final class SquareBracketSyntax extends AbstractBracketSyntax
{
    public function canParse(string $pattern): bool
    {
        return InlineConstraint::nextPlaceholder($pattern, 0, '[') !== null;
    }

    protected function openChar(): string
    {
        return '[';
    }

    protected function closeChar(): string
    {
        return ']';
    }

    protected function findClosing(string $pattern, int $start): int|false
    {
        return InlineConstraint::bracketEnd($pattern, $start, ']');
    }

    protected function parseParam(string $param): array
    {
        return InlineConstraint::bracketParameter($param, true);
    }

    protected function formatNormalized(array $parsed): string
    {
        return $parsed['optional'] ? '[?' . $parsed['name'] . ']' : '[' . $parsed['name'] . ']';
    }
}