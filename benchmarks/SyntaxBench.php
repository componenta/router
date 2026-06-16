<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Benchmarks;


use Componenta\Http\Router\Syntax\AngleBracketSyntax;
use Componenta\Http\Router\Syntax\ColonSyntax;
use Componenta\Http\Router\Syntax\CurlySyntax;
use Componenta\Http\Router\Syntax\SquareBracketSyntax;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Compare syntax parser performance
 */
#[Iterations(5)]
#[Warmup(2)]
class SyntaxBench
{
    private SquareBracketSyntax $squareBracket;
    private CurlySyntax $curly;
    private ColonSyntax $colon;
    private AngleBracketSyntax $angleBracket;
    private array $tokens;

    public function __construct()
    {
        $this->squareBracket = new SquareBracketSyntax();
        $this->curly = new CurlySyntax();
        $this->colon = new ColonSyntax();
        $this->angleBracket = new AngleBracketSyntax();
        $this->tokens = [];
    }

    // ============ PARSE ============

    #[Revs(1000)]
    #[Groups(['parse', 'squareBracket'])]
    public function benchParseSquareBracket(): void
    {
        $this->squareBracket->parse('/users/[id:\d+]/posts/[postId:\d+]');
    }

    #[Revs(1000)]
    #[Groups(['parse', 'curly'])]
    public function benchParseCurly(): void
    {
        $this->curly->parse('/users/{id:\d+}/posts/{postId:\d+}');
    }

    #[Revs(1000)]
    #[Groups(['parse', 'colon'])]
    public function benchParseColon(): void
    {
        $this->colon->parse('/users/:id(\d+)/posts/:postId(\d+)');
    }

    #[Revs(1000)]
    #[Groups(['parse', 'angleBracket'])]
    public function benchParseAngleBracket(): void
    {
        $this->angleBracket->parse('/users/<id:\d+>/posts/<postId:\d+>');
    }

    // ============ TO REGEX ============

    #[Revs(1000)]
    #[Groups(['toRegex', 'squareBracket'])]
    public function benchToRegexSquareBracket(): void
    {
        $this->squareBracket->toRegex('/users/[id:\d+]/posts/[postId:\d+]', $this->tokens);
    }

    #[Revs(1000)]
    #[Groups(['toRegex', 'curly'])]
    public function benchToRegexCurly(): void
    {
        $this->curly->toRegex('/users/{id:\d+}/posts/{postId:\d+}', $this->tokens);
    }

    #[Revs(1000)]
    #[Groups(['toRegex', 'colon'])]
    public function benchToRegexColon(): void
    {
        $this->colon->toRegex('/users/:id(\d+)/posts/:postId(\d+)', $this->tokens);
    }

    #[Revs(1000)]
    #[Groups(['toRegex', 'angleBracket'])]
    public function benchToRegexAngleBracket(): void
    {
        $this->angleBracket->toRegex('/users/<id:\d+>/posts/<postId:\d+>', $this->tokens);
    }

    // ============ BUILD PATH ============

    #[Revs(1000)]
    #[Groups(['buildPath', 'squareBracket'])]
    public function benchBuildPathSquareBracket(): void
    {
        $this->squareBracket->buildPath(
            '/users/[id:\d+]/posts/[postId:\d+]',
            ['id' => 42, 'postId' => 15],
            [],
            [],
            'test'
        );
    }

    #[Revs(1000)]
    #[Groups(['buildPath', 'curly'])]
    public function benchBuildPathCurly(): void
    {
        $this->curly->buildPath(
            '/users/{id:\d+}/posts/{postId:\d+}',
            ['id' => 42, 'postId' => 15],
            [],
            [],
            'test'
        );
    }

    #[Revs(1000)]
    #[Groups(['buildPath', 'colon'])]
    public function benchBuildPathColon(): void
    {
        $this->colon->buildPath(
            '/users/:id(\d+)/posts/:postId(\d+)',
            ['id' => 42, 'postId' => 15],
            [],
            [],
            'test'
        );
    }

    #[Revs(1000)]
    #[Groups(['buildPath', 'angleBracket'])]
    public function benchBuildPathAngleBracket(): void
    {
        $this->angleBracket->buildPath(
            '/users/<id:\d+>/posts/<postId:\d+>',
            ['id' => 42, 'postId' => 15],
            [],
            [],
            'test'
        );
    }

    // ============ COMPLEX PATTERNS ============

    #[Revs(1000)]
    #[Groups(['complex', 'squareBracket'])]
    public function benchComplexSquareBracket(): void
    {
        $this->squareBracket->parse('/blog[?year:\d{4}][?month:\d{2}][?day:\d{2}]');
    }

    #[Revs(1000)]
    #[Groups(['complex', 'curly'])]
    public function benchComplexCurly(): void
    {
        $this->curly->parse('/blog/{year:\d{4}?}/{month:\d{2}?}/{day:\d{2}?}');
    }

    #[Revs(1000)]
    #[Groups(['complex', 'colon'])]
    public function benchComplexColon(): void
    {
        $this->colon->parse('/blog/:year(\d{4})?/:month(\d{2})?/:day(\d{2})?');
    }

    #[Revs(1000)]
    #[Groups(['complex', 'angleBracket'])]
    public function benchComplexAngleBracket(): void
    {
        $this->angleBracket->parse('/blog/<year:\d{4}?>/<month:\d{2}?>/<day:\d{2}?>');
    }
}
