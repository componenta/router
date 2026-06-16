<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

/**
 * Contract for parsing route parameter syntax.
 *
 * Implementations define how parameters are expressed in route patterns.
 * For example, SquareBracketSyntax parses patterns like:
 * - [id] - required parameter
 * - [id:\d+] - required with regex constraint
 * - [?id] - optional parameter
 * - [?id=default] - optional with default value
 */
interface SyntaxParserInterface
{
    /**
     * Check if this syntax can parse the given pattern.
     *
     * Used by CompositeSyntax to auto-detect which parser to use.
     *
     * @param string $pattern The route pattern to check
     * @return bool True if this syntax recognizes the pattern
     */
    public function canParse(string $pattern): bool;

    /**
     * Parse a route pattern and extract parameter information.
     *
     * @param string $pattern The route pattern to parse
     * @return array{
     *     parameters: array<string, string|null>,
     *     optionalParameters: array<string, string|null>,
     *     tokens: array,
     *     defaults: array
     * }
     */
    public function parse(string $pattern): array;

    /**
     * Check if a path segment contains a parameter placeholder.
     *
     * @param string $segment A single path segment
     */
    public function hasParameter(string $segment): bool;

    /**
     * Convert a route pattern to a regular expression.
     *
     * @param string $pattern The route pattern
     * @param array $tokens Parameter regex constraints
     * @return string The compiled regex pattern
     */
    public function toRegex(string $pattern, array $tokens): string;

    /**
     * Normalize a pattern by removing inline constraints.
     *
     * Converts "/users/[id:\d+]" to "/users/[id]"
     *
     * @param string $pattern The pattern with inline constraints
     * @return string The normalized pattern
     */
    public function normalize(string $pattern): string;

    /**
     * Build a path by replacing parameters with values.
     *
     * @param string $pattern The route pattern
     * @param array<string, mixed> $params Parameter values
     * @param array<string, string> $tokens Parameter constraints for validation
     * @param array<string, mixed> $optional Optional parameter names
     * @param string $routeName Route name for error messages
     * @return string The built path
     * @throws \InvalidArgumentException If required parameter is missing or value doesn't match pattern
     */
    public function buildPath(string $pattern, array $params, array $tokens, array $optional, string $routeName): string;
}
