<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

final class RouteAttributeConflictException extends RouterException
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct(sprintf('Route parameter cannot replace existing or reserved request attribute "%s".', $attribute));
    }
}
