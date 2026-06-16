<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Syntax\CompositeSyntax;

/**
 * Route pattern compiler
 */
final readonly class Compiler implements CompilerInterface
{
    private array $defaultTokens;
    public SyntaxParserInterface $syntax;

    /**
     * @param SyntaxParserInterface|null $syntax Syntax parser (null = CompositeSyntax with all syntaxes)
     * @param array<string, string> $defaultPatterns Default parameter patterns
     */
    public function __construct(
        ?SyntaxParserInterface $syntax = null,
        array $defaultPatterns = [],
    ) {
        $this->syntax = $syntax ?? new CompositeSyntax();
        $this->defaultTokens = array_merge(
            self::DEFAULT_PATTERNS,
            $defaultPatterns
        );
    }

    public function compile(string $pattern, array $tokens = [], array $defaults = []): RouteCompileResult
    {
        $parsed = $this->syntax->parse($pattern);
        
        // Merge tokens: default -> inline -> explicit
        $mergedTokens = $this->defaultTokens;

        if (!empty($parsed['tokens'])) $mergedTokens = array_merge($mergedTokens, $parsed['tokens']);
        if ($tokens !== []) $mergedTokens = array_merge($mergedTokens, $tokens);

        $defaults = array_merge($parsed['defaults'], $defaults);

        $regex = $this->syntax->toRegex($pattern, $mergedTokens);

        return new RouteCompileResult(
            regex: $regex,
            parameters: $parsed['parameters'],
            optionalParameters: $parsed['optionalParameters'],
            tokens: $mergedTokens,
            defaults: $defaults,
        );
    }

    public function isParametrized(string $pattern): bool
    {
        return $this->syntax->hasParameter($pattern);
    }
}
