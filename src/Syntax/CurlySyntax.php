<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

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
        $len = strlen($pattern);
        $depth = 0;

        for ($i = $start; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    protected function parseParam(string $param): array
    {
        $name = '';
        $constraint = null;
        $optional = false;
        $default = null;

        $eqPos = strpos($param, '=');
        if ($eqPos !== false) {
            $default = substr($param, $eqPos + 1);
            $param = substr($param, 0, $eqPos);
        }

        if (str_ends_with($param, '?')) {
            $optional = true;
            $param = substr($param, 0, -1);
        }

        $colonPos = strpos($param, ':');
        if ($colonPos !== false) {
            $name = substr($param, 0, $colonPos);
            $c = substr($param, $colonPos + 1);
            $constraint = $c !== '' ? $c : null;
        } else {
            $name = $param;
        }

        return [
            'name' => $name,
            'constraint' => $constraint,
            'optional' => $optional || $default !== null,
            'default' => $default,
        ];
    }

    protected function formatNormalized(array $parsed): string
    {
        return $parsed['optional'] ? '{' . $parsed['name'] . '?}' : '{' . $parsed['name'] . '}';
    }
}