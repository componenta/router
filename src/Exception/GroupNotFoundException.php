<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

final class GroupNotFoundException extends RouterException
{
    public function __construct(
        public readonly string $groupName,
    ) {
        parent::__construct("Route group '{$groupName}' is not registered.");
    }
}
