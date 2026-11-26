<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

final readonly class RouteHandler
{
    public mixed $value;

    public function __construct(mixed $value)
    {
        if (is_array($value)) {
            $snapshot = [];
            foreach ($value as $key => $part) { $snapshot[$key] = $part; }
            $value = $snapshot;
        }
        $this->value = $value;
    }
}
