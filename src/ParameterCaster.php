<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

use Componenta\Http\Router\Internal\RouteParameters;

/** @internal Route parameter conversion. */
trait ParameterCaster
{
    private function castParameter(string $value): string|int|float
    {
        return RouteParameters::cast($value);
    }
}
