<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use ReflectionReference;
use UnitEnum;

/** @internal Integrity of trusted deployment data, not an authentication signature. */
final class PreparedMap
{
    private const int PERSISTABLE = 0;
    private const int OPAQUE = 1;
    private const int REFERENCED = 2;

    public static function seal(array $map): array
    {
        unset($map['fingerprint']);
        $map['fingerprint'] = self::inspect($map) === self::PERSISTABLE
            ? hash('xxh128', serialize($map)) : null;
        return $map;
    }

    public static function valid(array $map): bool
    {
        if (!array_key_exists('fingerprint', $map)) { return false; }
        $fingerprint = $map['fingerprint'];
        unset($map['fingerprint']);
        $state = self::inspect($map);
        if ($state === self::REFERENCED) { return false; }
        if ($state === self::OPAQUE) { return $fingerprint === null; }
        return is_string($fingerprint) && hash_equals($fingerprint, hash('xxh128', serialize($map)));
    }

    private static function inspect(array $map): int
    {
        $state = self::PERSISTABLE;
        foreach ($map as $key => $value) {
            // Detect cycles as well as external mutation through reference slots.
            if (ReflectionReference::fromArrayElement($map, $key) !== null) { return self::REFERENCED; }
            if (is_array($value)) {
                $nested = self::inspect($value);
                if ($nested === self::REFERENCED) { return $nested; }
                $state = max($state, $nested);
            } elseif (!is_scalar($value) && $value !== null && !$value instanceof UnitEnum) {
                // Never invoke serialization hooks on runtime handler objects.
                $state = self::OPAQUE;
            }
        }
        return $state;
    }

    private function __construct() {}
}
