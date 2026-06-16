<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

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
        return (bool) preg_match('/\[\??[a-zA-Z_]/', $pattern);
    }

    public function hasParameter(string $segment): bool
    {
        return (bool) preg_match('/\[\??[a-zA-Z_]/', $segment);
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
        $len = strlen($pattern);
        $depth = 0;
        $inCharClass = false;
        $afterColon = false;

        for ($i = $start + 1; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === ':' && !$afterColon && $depth === 0) {
                $afterColon = true;
                continue;
            }

            if ($afterColon) {
                if ($char === '[' && !$inCharClass) {
                    $inCharClass = true;
                    continue;
                }

                if ($char === ']' && $inCharClass) {
                    $inCharClass = false;
                    continue;
                }

                if ($char === '{') {
                    $depth++;
                    continue;
                }

                if ($char === '}') {
                    $depth--;
                    continue;
                }
            }

            if ($char === ']' && !$inCharClass && $depth === 0) {
                return $i;
            }
        }

        return false;
    }

    protected function parseParam(string $param): array
    {
        $constraint = null;
        $optional = false;
        $default = null;

        $pos = 0;
        $len = strlen($param);

        // Optional marker at start: [?name]
        if ($len > 0 && $param[0] === '?') {
            $optional = true;
            $pos = 1;
        }

        // Extract name
        $nameStart = $pos;
        while ($pos < $len && (ctype_alnum($param[$pos]) || $param[$pos] === '_')) {
            $pos++;
        }
        $name = substr($param, $nameStart, $pos - $nameStart);

        // Validate name
        if ($name === '' || ctype_digit($name[0])) {
            return ['name' => '', 'constraint' => null, 'optional' => false, 'default' => null];
        }

        // Extract constraint after colon
        if ($pos < $len && $param[$pos] === ':') {
            $pos++;
            $constraintStart = $pos;
            $depth = 0;

            while ($pos < $len) {
                $char = $param[$pos];
                if ($char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === '=' && $depth === 0) {
                    break;
                }
                $pos++;
            }

            $constraint = substr($param, $constraintStart, $pos - $constraintStart);
            if ($constraint === '') {
                $constraint = null;
            }
        }

        // Extract default value
        if ($pos < $len && $param[$pos] === '=') {
            $default = substr($param, $pos + 1);
            $optional = true;
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
        return $parsed['optional'] ? '[?' . $parsed['name'] . ']' : '[' . $parsed['name'] . ']';
    }
}