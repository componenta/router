<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Contract\SyntaxParserInterface;

/**
 * Composite syntax that auto-detects and delegates to appropriate parser.
 *
 * Allows mixing different syntaxes in one project:
 * - /users/{id}        -> CurlySyntax (Laravel)
 * - /posts/:id         -> ColonSyntax (Express)
 * - /pages/[id]        -> SquareBracketSyntax
 * - /items/<id>        -> AngleBracketSyntax
 *
 * Detection priority (first match wins):
 * 1. CurlySyntax     - { is unambiguous
 * 2. AngleBracketSyntax - < followed by letter is unambiguous
 * 3. ColonSyntax     - :name but not :// or ::
 * 4. SquareBracketSyntax - [name] but [] can be in regex constraints
 */
final readonly class CompositeSyntax implements SyntaxParserInterface
{
    /** @var list<SyntaxParserInterface> */
    private array $syntaxes;

    private SyntaxParserInterface $fallback;

    /**
     * @param list<SyntaxParserInterface>|null $syntaxes Ordered list of syntaxes to try
     * @param SyntaxParserInterface|null $fallback Fallback when no syntax matches
     */
    public function __construct(
        ?array $syntaxes = null,
        ?SyntaxParserInterface $fallback = null,
    ) {
        $this->syntaxes = $syntaxes ?? [
            new CurlySyntax(),         // { is unambiguous
            new AngleBracketSyntax(),  // <name is unambiguous
            new ColonSyntax(),         // :name (careful with :// and ::)
            new SquareBracketSyntax(), // [name] ([] can appear in regex)
        ];

        $this->fallback = $fallback ?? new SquareBracketSyntax();
    }

    /**
     * Create with all available syntaxes in optimal detection order.
     */
    public static function all(): self
    {
        return new self();
    }

    /**
     * Detect which syntax the pattern uses.
     */
    public function detect(string $pattern): SyntaxParserInterface
    {
        foreach ($this->syntaxes as $syntax) {
            if ($syntax->canParse($pattern)) {
                return $syntax;
            }
        }

        return $this->fallback;
    }

    public function canParse(string $pattern): bool
    {
        // Composite can attempt to parse any pattern
        if (array_any($this->syntaxes, static fn($syntax) => $syntax->canParse($pattern))) {
            return true;
        }

        return $this->fallback->canParse($pattern);
    }

    public function parse(string $pattern): array
    {
        return $this->detect($pattern)->parse($pattern);
    }

    public function hasParameter(string $segment): bool
    {
        return $this->detect($segment)->hasParameter($segment);
    }

    public function toRegex(string $pattern, array $tokens): string
    {
        return $this->detect($pattern)->toRegex($pattern, $tokens);
    }

    public function normalize(string $pattern): string
    {
        return $this->detect($pattern)->normalize($pattern);
    }

    public function buildPath(string $pattern, array $params, array $tokens, array $optional, string $routeName): string
    {
        return $this->detect($pattern)->buildPath($pattern, $params, $tokens, $optional, $routeName);
    }
}
