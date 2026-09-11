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
}
