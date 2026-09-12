<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Catalog\Row;
use Doctrine\DBAL\Connection;

/**
 * The passive tree as the legality rules need it: who touches whom, which
 * keystones cover a node, and what gates it.
 *
 * Loaded in three queries on first use. A rules evaluation walks thousands of
 * nodes, so a query per node is not an option.
 */
final class PassiveGraph
{
    /** @var array<string, list<string>>|null */
    private ?array $neighbours = null;

    /** @var array<string, list<string>> */
    private array $radius = [];

    /** @var array<string, array{nodes: list<string>, ascendancy: string|null}> */
    private array $constraints = [];

    /** @var array<string, string> */
    private array $kinds = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<string>
     */
    public function neighbours(string $id): array
    {
        $this->load();

        return $this->neighbours[$id] ?? [];
    }

    /**
     * @return list<string>
     */
    public function keystonesCovering(string $id): array
    {
        $this->load();

        return $this->radius[$id] ?? [];
    }

    /**
     * @return array{nodes: list<string>, ascendancy: string|null}|null
     */
    public function unlockConstraintOf(string $id): ?array
    {
        $this->load();

        return $this->constraints[$id] ?? null;
    }

    public function isKeystone(string $id): bool
    {
        $this->load();

        return 'keystone' === ($this->kinds[$id] ?? null);
    }

    public function exists(string $id): bool
    {
        $this->load();

        return isset($this->kinds[$id]);
    }

    private function load(): void
    {
        if (null !== $this->neighbours) {
            return;
        }

        $neighbours = [];

        // Stored edges are directed. Connectivity is not, so both directions
        // are recorded whatever the table happens to hold.
        foreach ($this->db->fetchAllAssociative('SELECT from_id, to_id FROM catalog_passive_edge') as $row) {
            $from = Row::str($row, 'from_id');
            $to = Row::str($row, 'to_id');

            $neighbours[$from][$to] = true;
            $neighbours[$to][$from] = true;
        }

        foreach ($this->db->fetchAllAssociative('SELECT id, kind, keystones_in_radius, unlock_constraint FROM catalog_passive') as $row) {
            $id = Row::str($row, 'id');
            $this->kinds[$id] = Row::str($row, 'kind');

            $covering = Row::jsonStrings($row, 'keystones_in_radius');
            if ([] !== $covering) {
                $this->radius[$id] = $covering;
            }

            $constraint = $this->constraint($row);
            if (null !== $constraint) {
                $this->constraints[$id] = $constraint;
            }
        }

        $this->neighbours = array_map(array_keys(...), $neighbours);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{nodes: list<string>, ascendancy: string|null}|null
     */
    private function constraint(array $row): ?array
    {
        $raw = $row['unlock_constraint'] ?? null;

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $nodes = array_values(array_filter((array) ($decoded['nodes'] ?? []), is_string(...)));

        return [] === $nodes ? null : [
            'nodes' => $nodes,
            'ascendancy' => \is_string($decoded['ascendancy'] ?? null) ? $decoded['ascendancy'] : null,
        ];
    }
}
