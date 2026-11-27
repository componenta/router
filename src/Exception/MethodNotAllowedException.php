<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

/**
 * Thrown when route exists but method is not allowed (405)
 */
final class MethodNotAllowedException extends RouterException
{
    /** @var list<string> */
    public readonly array $allowedMethods;

    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public private(set) readonly string $uri,
        public private(set) readonly string $method,
        array $allowedMethods,
    ) {
        // A readonly array can still contain caller-owned reference slots.
        // Keep the message and both HTTP representations tied to one snapshot.
        $this->allowedMethods = array_map(static fn (mixed $method): mixed => $method, $allowedMethods);
        $allowed = implode(', ', $this->allowedMethods);
        parent::__construct("Method {$method} not allowed for {$uri}. Allowed: {$allowed}", 405);
    }

    public string $allowHeader {
        get => implode(', ', $this->allowedMethods);
    }
}
