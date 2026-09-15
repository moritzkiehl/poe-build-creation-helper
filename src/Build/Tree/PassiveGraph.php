<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Catalog\Row;
use Doctrine\DBAL\Connection;

/**
 * The passive tree as the legality rules need it: who touches whom, which
 * keystones cover a node, where it sits, and what gates it.
 *
 * Loaded in two queries on first use — the edges, then the nodes. A rules evaluation walks thousands of
 * nodes, so a query per node is not an option.
 */
final class PassiveGraph
{
    /**
     * How far a keystone-radius jewel reaches from its keystone, in stored
     * position units. The jewel's mod
     * (`JewelUniqueAllocateDisconnectedPassivesAroundKeystone`) carries a
     * `local_jewel_effect_base_radius` of 1000. One export unit is assumed to
     * equal one unit of that radius until spec proof 12's in-game check.
     */
    private const float JEWEL_RADIUS = 1000.0;

    /** @var array<string, list<string>>|null */
    private ?array $neighbours = null;

    /** @var array<string, list<string>> */
    private array $radius = [];

    /** @var array<string, array{nodes: list<string>, ascendancy: string|null}> */
    private array $constraints = [];

    /** @var array<string, string> */
    private array $kinds = [];

    /** @var array<string, array{0: float, 1: float}> */
    private array $positions = [];

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

    /**
     * Whether a jewel declared around `$keystoneId` reaches `$id`: a
     * non-keystone node within `JEWEL_RADIUS` of the keystone. Its own
     * coverage, not `keystonesCovering()` — that list is Entwined Realities'.
     */
    public function jewelCovers(string $keystoneId, string $id): bool
    {
        $this->load();

        if (!isset($this->positions[$keystoneId], $this->positions[$id]) || $this->isKeystone($id)) {
            return false;
        }

        [$kx, $ky] = $this->positions[$keystoneId];
        [$x, $y] = $this->positions[$id];

        return hypot($x - $kx, $y - $ky) <= self::JEWEL_RADIUS;
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

        foreach ($this->db->fetchAllAssociative('SELECT id, kind, pos_x, pos_y, keystones_in_radius, unlock_constraint FROM catalog_passive') as $row) {
            $id = Row::str($row, 'id');
            $this->kinds[$id] = Row::str($row, 'kind');
            $this->positions[$id] = [Row::float($row, 'pos_x'), Row::float($row, 'pos_y')];

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
