<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Writes normalised catalog rows straight through DBAL.
 *
 * The catalog is read-only reference data measured in thousands of rows, and it
 * is rebuilt wholesale roughly once per game patch. Hydrating it through the ORM
 * would cost time and buy nothing.
 */
final class BulkWriter
{
    private const int BATCH = 500;

    /**
     * @param list<string>                     $columns
     * @param list<array<string, scalar|null>> $rows
     */
    public function insert(Connection $db, string $table, array $columns, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholder = '('.implode(', ', array_fill(0, \count($columns), '?')).')';

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $params[] = $row[$column] ?? null;
                }
            }

            $db->executeStatement(
                \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($chunk), $placeholder))),
                $params,
            );
        }
    }
}
