<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

/**
 * Result of route pattern compilation
 */
final readonly class RouteCompileResult
{
    /**
     * @param string $regex Compiled regex pattern
     * @param array<string, string|null> $parameters Required parameters: name => pattern|null
     * @param array<string, string|null> $optionalParameters Optional parameters: name => pattern|null
     * @param array $tokens Inline tokens from pattern
     * @param array $defaults Inline defaults from pattern
     */
    public function __construct(
        public string $regex,
        public array $parameters = [],
        public array $optionalParameters = [],
        public array $tokens = [],
        public array $defaults = [],
    ) {}

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
