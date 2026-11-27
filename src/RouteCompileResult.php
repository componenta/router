<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use ReflectionReference;

/**
 * Result of route pattern compilation. Owns the top-level metadata slots.
 * Nested default values retain their original PHP value semantics.
 */
final readonly class RouteCompileResult
{
    public array $parameters;
    public array $optionalParameters;
    public array $tokens;
    public array $defaults;

    /**
     * @param string $regex Compiled regex pattern
     * @param array<string, string|null> $parameters Required parameters: name => pattern|null
     * @param array<string, string|null> $optionalParameters Optional parameters: name => pattern|null
     * @param array<string, string> $tokens Inline tokens from pattern
     * @param array<string, mixed> $defaults Inline defaults from pattern
     */
    public function __construct(
        public string $regex,
        array $parameters = [],
        array $optionalParameters = [],
        array $tokens = [],
        array $defaults = [],
    ) {
        $this->parameters = self::snapshot($parameters);
        $this->optionalParameters = self::snapshot($optionalParameters);
        $this->tokens = self::snapshot($tokens);
        $this->defaults = self::snapshot($defaults);
    }

    private static function snapshot(array $values): array
    {
        // Preserve copy-on-write sharing on the ordinary reference-free path.
        foreach ($values as $key => $_) {
            if (ReflectionReference::fromArrayElement($values, $key) !== null) {
                $copy = [];
                foreach ($values as $name => $value) {
                    $copy[$name] = $value;
                }
                return $copy;
            }
        }
        return $values;
    }

    public function hasParameters(): bool
    {
        return $this->parameters !== [] || $this->optionalParameters !== [];
    }

    /** @return list<string> */
    public function parameterNames(): array
    {
        $keys = array_keys($this->parameters + $this->optionalParameters);
        // Filter only string keys (valid parameter names)
        return array_values(array_filter($keys, 'is_string'));
    }
}
