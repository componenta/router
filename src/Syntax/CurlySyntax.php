<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Internal\InlineConstraint;

/**
 * Curly brace parameter syntax (Laravel/Symfony style).
 *
 * Supports:
 * - {id} - required parameter
 * - {id:\d+} - required with pattern
 * - {id?} - optional parameter
 * - {id:\d+?} - optional with pattern
 * - {id=default} - with default (implies optional)
 * - {id:\d+=5} - with pattern and default
 */
final class CurlySyntax extends AbstractBracketSyntax
{
    public function canParse(string $pattern): bool
    {
        return (bool) preg_match('/\{[a-zA-Z_]/', $pattern);
    }

    protected function openChar(): string
    {
        return '{';
    }

    protected function closeChar(): string
    {
        return '}';
    }

    protected function findClosing(string $pattern, int $start): int|false
    {
        return InlineConstraint::bracketEnd($pattern, $start, '}');
    }

    protected function parseParam(string $param): array
    {
        return InlineConstraint::bracketParameter($param, false);
    }

    protected function formatNormalized(array $parsed): string
    {
        return $parsed['optional'] ? '{' . $parsed['name'] . '?}' : '{' . $parsed['name'] . '}';
    }
}