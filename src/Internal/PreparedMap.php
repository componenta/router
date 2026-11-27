<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use ReflectionReference;
use UnitEnum;

/** @internal Integrity of trusted deployment data, not an authentication signature. */
final class PreparedMap
{
    private const int FLOATS = 1;
    private const int OPAQUE = 2;
    private const int REFERENCED = 4;

    public static function seal(array $map): array
    {
        unset($map['fingerprint']);
        $map['fingerprint'] = self::fingerprint($map);
        return $map;
    }

    public static function valid(array $map): bool
    {
        $fingerprint = $map['fingerprint'] ?? null;
        if (!is_string($fingerprint)) { return false; }
        unset($map['fingerprint']);
        $expected = self::fingerprint($map);
        return $expected !== null && hash_equals($expected, $fingerprint);
    }

    private static function fingerprint(array $map): ?string
    {
        $state = self::inspect($map);
        if (($state & self::REFERENCED) !== 0) { return null; }
        if ($state === 0) { return hash('xxh128', serialize($map)); }

        // Serialize only scalar, array and enum data. Float bytes are independent
        // of serialize_precision; opaque runtime handlers retain their identity.
        // Positions and types are stored separately to avoid placeholder collisions.
        $special = [];
        $normalized = self::normalize($map, [], $special) ?? $map;
        return hash('xxh128', serialize([$normalized, $special]));
    }

    private static function inspect(array $map): int
    {
        $state = 0;
        foreach ($map as $key => $value) {
            if (ReflectionReference::fromArrayElement($map, $key) !== null) { return self::REFERENCED; }
            if (is_array($value)) {
                $nested = self::inspect($value);
                if (($nested & self::REFERENCED) !== 0) { return $nested; }
                $state |= $nested;
            } elseif (is_float($value)) {
                $state |= self::FLOATS;
            } elseif (!is_scalar($value) && $value !== null && !$value instanceof UnitEnum) {
                $state |= self::OPAQUE;
            }
        }
        return $state;
    }

    /** Return null when the subtree needs no normalization, preserving copy-on-write sharing. */
    private static function normalize(array $map, array $path, array &$special): ?array
    {
        $changed = false;
        foreach ($map as $key => $value) {
            if (is_array($value)) {
                $child = self::normalize($value, [...$path, $key], $special);
                if ($child !== null) {
                    $map[$key] = $child;
                    $changed = true;
                }
            } elseif (is_float($value)) {
                $special[] = [[...$path, $key], 'float', is_nan($value) ? 'nan' : pack('E', $value)];
                $map[$key] = null;
                $changed = true;
            } elseif (is_object($value) && !$value instanceof UnitEnum) {
                $special[] = [[...$path, $key], 'object', $value::class, spl_object_id($value)];
                $map[$key] = null;
                $changed = true;
            } elseif (is_resource($value) || gettype($value) === 'resource (closed)') {
                $special[] = [[...$path, $key], 'resource', gettype($value), (int) $value];
                $map[$key] = null;
                $changed = true;
            }
        }
        return $changed ? $map : null;
    }

    private function __construct() {}
}
