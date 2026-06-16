<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

/**
 * Thrown when route exists but method is not allowed (405)
 */
final class MethodNotAllowedException extends RouterException
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public private(set) readonly string $uri,
        public private(set) readonly string $method,
        public private(set) readonly array $allowedMethods,
    ) {
        $allowed = implode(', ', $allowedMethods);
        parent::__construct("Method {$method} not allowed for {$uri}. Allowed: {$allowed}");
    }

    public string $allowHeader {
        get => implode(', ', $this->allowedMethods);
    }
}
