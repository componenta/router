<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

/**
 * Contract for converting route paths between parameter syntaxes.
 *
 * Implement this interface to add support for custom target syntaxes
 * or custom source syntax detection.
 */
interface SyntaxConverterInterface
{
    /**
     * Convert path to ColonSyntax format.
     *
     * Required parameters become `:name`, optional become `:name?`.
     * Existing separators are preserved; inline constraints and defaults
     * are removed. Consume the result with ColonSyntax.
     * @throws \InvalidArgumentException If a normalized layout cannot be represented without ambiguity
     *
     * @param string $path Path in any supported syntax
     * @return string Path in ColonSyntax format
     */
    public function toColonSyntax(string $path): string;
}