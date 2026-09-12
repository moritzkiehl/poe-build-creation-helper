<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Typed reads off a DBAL row.
 *
 * DBAL hands back `mixed` for every column, and casting that straight into a
 * typed value object only looks safe. These three say what is expected and fall
 * back rather than coerce something surprising.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function str(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableStr(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Unlike the other accessors here, a numeric column is not reliably a PHP
     * string: the driver hands back a native float for FLOAT/DOUBLE columns
     * fetched outside the ORM, so this checks `is_numeric()` rather than
     * `is_string()`.
     *
     * @param array<string, mixed> $row
     */
    public static function float(array $row, string $key, float $default = 0.0): float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * A JSON column holding a list of strings. Anything that is not a string —
     * a malformed row, a schema that moved — is dropped rather than coerced,
     * matching how the other readers here behave.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public static function jsonStrings(array $row, string $key): array
    {
        $decoded = json_decode(self::str($row, $key, '[]'), true);

        return array_values(array_filter(\is_array($decoded) ? $decoded : [], is_string(...)));
    }
}
