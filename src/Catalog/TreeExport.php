<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The whole passive tree in one payload, for the canvas renderer.
 *
 * Nodes are tuples rather than objects: five thousand of them with six named
 * keys each is a payload several times larger than it needs to be, and the
 * only consumer is our own Stimulus controller. The order is fixed by
 * contract: id, name, kind, ascendancy key, x, y.
 */
final class TreeExport
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{nodes: list<array{0: string, 1: string, 2: string, 3: string|null, 4: float, 5: float}>, edges: list<array{0: string, 1: string}>, classes: list<array{id: string, start_node_id: string, ascendancies: list<array{id: string, name: string}>}>}
     */
    public function payload(): array
    {
        $nodes = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, name, kind, ascendancy_key, pos_x, pos_y FROM catalog_passive') as $row) {
            $nodes[] = [
                Row::str($row, 'id'),
                Row::str($row, 'name'),
                Row::str($row, 'kind'),
                Row::nullableStr($row, 'ascendancy_key'),
                (float) Row::str($row, 'pos_x', '0'),
                (float) Row::str($row, 'pos_y', '0'),
            ];
        }

        $edges = [];
        foreach ($this->db->fetchAllAssociative('SELECT from_id, to_id FROM catalog_passive_edge') as $row) {
            $edges[] = [Row::str($row, 'from_id'), Row::str($row, 'to_id')];
        }

        $classes = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, start_node_id, ascendancies FROM catalog_class ORDER BY id') as $row) {
            $decoded = json_decode(Row::str($row, 'ascendancies', '[]'), true);
            $ascendancies = [];
            foreach (\is_array($decoded) ? $decoded : [] as $ascendancy) {
                if (\is_array($ascendancy) && \is_string($ascendancy['id'] ?? null) && \is_string($ascendancy['name'] ?? null)) {
                    $ascendancies[] = ['id' => $ascendancy['id'], 'name' => $ascendancy['name']];
                }
            }

            $classes[] = [
                'id' => Row::str($row, 'id'),
                'start_node_id' => Row::str($row, 'start_node_id'),
                'ascendancies' => $ascendancies,
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'classes' => $classes];
    }

    /**
     * When the stored tree last changed. Null means nothing has been synced.
     */
    public function revision(): ?\DateTimeImmutable
    {
        $value = $this->db->fetchOne("SELECT MAX(ran_at) FROM catalog_sync WHERE source = 'passive_tree' AND status = 'ok'");

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
