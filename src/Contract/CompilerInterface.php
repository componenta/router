<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Contract;

use Componenta\Http\Router\RouteCompileResult;

/**
 * Contract for compiling route patterns into regular expressions.
 *
 * The compiler transforms route patterns with parameters (e.g., "/users/[id]")
 * into regex patterns suitable for matching incoming requests.
 */
interface CompilerInterface
{
    /**
     * Default regex patterns for common parameter types.
     */
    public const array DEFAULT_PATTERNS = [
        'id' => '\d+',
        'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'slug' => '[a-z0-9-]+',
        'any' => '.+',
    ];

    /**
     * Compile a route pattern into a regular expression.
     *
     * @param string $pattern The route pattern (e.g., "/users/[id:\d+]")
     * @param array $tokens Custom parameter constraints
     * @param array $defaults Default parameter values
     * @return RouteCompileResult The compiled regex and parameter information
     */
    public function compile(string $pattern, array $tokens = [], array $defaults = []): RouteCompileResult;

    /**
     * Check if a pattern contains any parameters.
     *
     * @param string $pattern The route pattern to check
     * @return bool True if the pattern has parameters
     */
    public function isParametrized(string $pattern): bool;
}
