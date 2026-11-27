<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Contract\SyntaxConverterInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;
use Componenta\Http\Router\Internal\InlineConstraint;
use InvalidArgumentException;

/**
 * Converts normalized parameter layouts to colon syntax.
 *
 * Inline constraints and defaults are deliberately removed. Literal path
 * segments and the names and optionality of parameters are preserved.
 */
class SyntaxConverter implements SyntaxConverterInterface
{
    public function __construct(public SyntaxParserInterface $syntax = new CompositeSyntax()) {}

    public function toColonSyntax(string $path): string
    {
        $source = $this->syntax instanceof CompositeSyntax ? $this->syntax->detect($path) : $this->syntax;
        $parsed = $source->parse($path);
        $required = array_keys($parsed['parameters']);
        $optional = array_keys($parsed['optionalParameters']);
        $normalized = $source->normalize($path);
        $result = $this->replaceParameters($normalized, $required, $optional);
        $target = new ColonSyntax();
        $converted = $target->parse($result);
        if (array_keys($converted['parameters']) !== $required
            || array_keys($converted['optionalParameters']) !== $optional
            || (self::isBuiltIn($source) && $source->toRegex($normalized, []) !== $target->toRegex($result, []))) {
            throw new InvalidArgumentException('Route layout cannot be represented in colon syntax: ' . $path);
        }
        return $result;
    }

    private static function isBuiltIn(SyntaxParserInterface $syntax): bool
    {
        return in_array($syntax::class, [CurlySyntax::class, SquareBracketSyntax::class, AngleBracketSyntax::class, ColonSyntax::class], true);
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    protected function replaceParameters(string $path, array $required, array $optional): string
    {
        if ($required === [] && $optional === []) { return $path; }
        $source = $this->syntax instanceof CompositeSyntax ? $this->syntax->detect($path) : $this->syntax;
        if ($source instanceof ColonSyntax) { return $path; }
        if (self::isBuiltIn($source)) {
            $replacements = [];
            foreach ([[$required, ''], [$optional, '?']] as [$names, $marker]) {
                foreach ($names as $name) {
                    $placeholder = match ($source::class) {
                        CurlySyntax::class => '{' . $name . $marker . '}',
                        SquareBracketSyntax::class => '[' . $marker . $name . ']',
                        AngleBracketSyntax::class => '<' . $name . $marker . '>',
                    };
                    $replacements[$placeholder] = $this->replaceParameter($placeholder, $name, ':' . $name . $marker);
                }
            }
            [$open, $close] = match ($source::class) {
                CurlySyntax::class => ['{', '}'],
                SquareBracketSyntax::class => ['[', ']'],
                AngleBracketSyntax::class => ['<', '>'],
            };
            // The source parser may have skipped an invalid literal bracket
            // pair containing text that looks like a real placeholder.
            $result = '';
            $offset = 0;
            while (($start = strpos($path, $open, $offset)) !== false) {
                $end = InlineConstraint::bracketEnd($path, $start, $close);
                if ($end === false) { break; }
                $placeholder = substr($path, $start, $end - $start + 1);
                $result .= substr($path, $offset, $start - $offset) . ($replacements[$placeholder] ?? $placeholder);
                $offset = $end + 1;
            }
            return $result . substr($path, $offset);
        }
        foreach ($optional as $name) { $path = $this->replaceParameter($path, $name, ':' . $name . '?'); }
        foreach ($required as $name) { $path = $this->replaceParameter($path, $name, ':' . $name); }
        return $path;
    }

    protected function replaceParameter(string $path, string $name, string $replacement): string
    {
        // Built-in conversion supplies an exact normalized placeholder.
        if (in_array($path, [
            '{' . $name . '}', '{' . $name . '?}', '<' . $name . '>', '<' . $name . '?>',
            '[' . $name . ']', '[?' . $name . ']', ':' . $name, ':' . $name . '?',
        ], true)) {
            return $replacement;
        }
        $q = preg_quote($name, '#');
        return preg_replace([
            '#\{' . $q . '\?}#', '#\{' . $q . '}#', '#<' . $q . '\?>#', '#<' . $q . '>#',
            '#:' . $q . '\?(?=/|$)#', '#:' . $q . '(?=/|$)#', '#\[\?' . $q . ']#', '#\[' . $q . ']#',
        ], $replacement, $path);
    }
}
