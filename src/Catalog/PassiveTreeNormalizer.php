<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Turns the official skill tree export into allocatable nodes and edges.
 *
 * Two kinds of entry are deliberately dropped, both established by reading the
 * 0.5.5 export: the synthetic `root` node, which is a layout anchor rather than
 * a passive, and the 240 nodes carrying `id: null`, which are unnamed
 * ascendancy filler and cannot be referenced from a `.build` file.
 *
 * Node ids are stored exactly as the export writes them. 527 of them end in an
 * underscore; normalising that away would break export into the game.
 */
final class PassiveTreeNormalizer
{
    public function normalize(string $json): NormalizedTree
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The skill tree export is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (!\is_array($data) || !isset($data['nodes']) || !\is_array($data['nodes'])) {
            throw new \RuntimeException('The skill tree export has no "nodes" object.');
        }

        /** @var array<string, array<string, mixed>> $rawNodes */
        $rawNodes = $data['nodes'];

        $nodes = [];
        $allocatable = [];

        foreach ($rawNodes as $key => $node) {
            $id = $node['id'] ?? null;
            if (!\is_string($id) || '' === $id || 'root' === $key) {
                continue;
            }

            $allocatable[(string) $key] = $id;
            $nodes[] = [
                'id' => $id,
                'name' => \is_string($node['name'] ?? null) ? $node['name'] : '',
                'kind' => $this->kind($node),
                'ascendancy_key' => \is_string($node['ascendancyId'] ?? null) ? $node['ascendancyId'] : null,
                'pos_x' => is_numeric($node['x'] ?? null) ? (float) $node['x'] : 0.0,
                'pos_y' => is_numeric($node['y'] ?? null) ? (float) $node['y'] : 0.0,
                'stats' => array_values(array_filter((array) ($node['stats'] ?? []), is_string(...))),
            ];
        }

        return new NormalizedTree(
            nodes: $nodes,
            edges: $this->edges($rawNodes, $allocatable),
            treeVariant: \is_string($data['tree'] ?? null) ? $data['tree'] : null,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function kind(array $node): string
    {
        return match (true) {
            true === ($node['isKeystone'] ?? false) => 'keystone',
            true === ($node['isNotable'] ?? false) => 'notable',
            default => 'small',
        };
    }

    /**
     * Edges are read from each node's own `out` list rather than the top-level
     * `edges` array, because that array also carries the root's spokes and
     * refers to nodes by numeric key.
     *
     * @param array<string, array<string, mixed>> $rawNodes
     * @param array<string, string>               $allocatable key in the export => passive id
     *
     * @return list<array{string, string}>
     */
    private function edges(array $rawNodes, array $allocatable): array
    {
        $edges = [];
        $seen = [];

        foreach ($rawNodes as $key => $node) {
            if (!isset($allocatable[(string) $key])) {
                continue;
            }

            foreach ((array) ($node['out'] ?? []) as $target) {
                if (!\is_string($target) && !\is_int($target)) {
                    continue;
                }

                if (!isset($allocatable[(string) $target])) {
                    continue;
                }

                $pair = [$allocatable[(string) $key], $allocatable[(string) $target]];
                $fingerprint = implode("\0", $pair);
                if (isset($seen[$fingerprint])) {
                    continue;
                }

                $seen[$fingerprint] = true;
                $edges[] = $pair;
            }
        }

        return $edges;
    }
}
