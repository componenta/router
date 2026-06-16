<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Syntax;

use Componenta\Http\Router\Contract\SyntaxConverterInterface;
use Componenta\Http\Router\Contract\SyntaxParserInterface;

/**
 * Converts route paths between different parameter syntaxes.
 *
 * Uses SyntaxParserInterface::parse() to extract parameter information,
 * then replaces placeholders with target syntax format.
 *
 * Extensible: extend this class and override methods for custom syntaxes.
 */
class SyntaxConverter implements SyntaxConverterInterface
{
    public function __construct(
            public SyntaxParserInterface $syntax = new CompositeSyntax(),
    ){
    }

    /**
     * Convert path to ColonSyntax format.
     *
     * Required parameters become `:name`, optional become `/:name?`.
     *
     * @param string $path Path in any supported syntax
     * @return string Path in ColonSyntax format
     */
    public function toColonSyntax(string $path): string
    {
        $parsed = $this->syntax->parse($path);

        $required = array_keys($parsed['parameters']);
        $optional = array_keys($parsed['optionalParameters']);

        if (empty($required) && empty($optional)) {
            return $path;
        }

        // Normalize first to strip constraints - simplifies replacement
        $normalized = $this->syntax->normalize($path);

        $result = $this->replaceParameters($normalized, $required, $optional);

        return str_contains($result, '//') ? preg_replace('#/+#', '/', $result) : $result;
    }

    /**
     * Replace all parameter placeholders with ColonSyntax format.
     *
     * Works on normalized path (no constraints), so simple regex suffices.
     *
     * @param string $path Normalized path
     * @param list<string> $required Required parameter names
     * @param list<string> $optional Optional parameter names
     * @return string Path with replaced parameters
     */
    protected function replaceParameters(string $path, array $required, array $optional): string
    {
        // Replace optional parameters first (they have markers like ? or [?name])
        foreach ($optional as $name) {
            $path = $this->replaceParameter($path, $name, '/:' . $name . '?');
        }

        // Replace required parameters
        foreach ($required as $name) {
            $path = $this->replaceParameter($path, $name, ':' . $name);
        }

        return $path;
    }

    /**
     * Replace single parameter in normalized path.
     *
     * Handles all syntax forms after normalization (no constraints).
     *
     * @param string $path Normalized path
     * @param string $name Parameter name
     * @param string $replacement Replacement string
     * @return string Path with replaced parameter
     */
    protected function replaceParameter(string $path, string $name, string $replacement): string
    {
        $q = preg_quote($name, '#');

        // Patterns for normalized paths (no constraints)
        return preg_replace(
                [
                        '#\{' . $q . '\?}#',        // {name?}
                        '#\{' . $q . '}#',          // {name}
                        '#<' . $q . '\?>#',         // <name?\>
                        '#<' . $q . '>#',           // <name>
                        '#:' . $q . '\?(?=/|$)#',   // :name?
                        '#:' . $q . '(?=/|$)#',     // :name
                        '#\[\?' . $q . ']#',        // [?name]
                        '#\[' . $q . ']#',          // [name]
                ],
                $replacement,
                $path
        );
    }
}