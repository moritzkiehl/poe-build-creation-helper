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

        /** @var array<string, mixed> $data */

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
                'recipe' => array_values(array_filter((array) ($node['recipe'] ?? []), is_string(...))),
                'keystones_in_radius' => [],
                'unlock_constraint' => null,
            ];
        }

        /**
         * The reverse of $allocatable, built once so that resolving the legality
         * fields below is O(1) per node instead of an array_search over all of
         * $rawNodes for every one of them.
         *
         * @var array<string, string|int> $keyById
         */
        $keyById = array_flip($allocatable);

        foreach ($nodes as $index => $node) {
            $raw = $rawNodes[$keyById[$node['id']] ?? ''] ?? null;
            if (!\is_array($raw)) {
                continue;
            }

            $nodes[$index]['keystones_in_radius'] = $this->resolveKeys($raw['keystonesInRadius'] ?? null, $allocatable);
            $nodes[$index]['unlock_constraint'] = $this->unlockConstraint($raw['unlockConstraint'] ?? null, $allocatable);
        }

        return new NormalizedTree(
            nodes: $nodes,
            edges: $this->edges($rawNodes, $allocatable),
            classes: $this->classes($data, $rawNodes),
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

    /**
     * The export names classes in one array and marks start nodes with the
     * indices they serve. Six nodes cover twelve classes, in pairs.
     *
     * @param array<string, mixed>                $data
     * @param array<string, array<string, mixed>> $rawNodes
     *
     * @return list<array{id: string, start_node_id: string, base_str: int, base_dex: int, base_int: int, ascendancies: list<array{id: string, name: string}>}>
     */
    private function classes(array $data, array $rawNodes): array
    {
        $startNodes = $this->startNodesByClassIndex($rawNodes);
        $classes = [];

        foreach (array_values((array) ($data['classes'] ?? [])) as $index => $class) {
            if (!\is_array($class) || !\is_string($class['name'] ?? null) || !isset($startNodes[$index])) {
                continue;
            }

            $classes[] = [
                'id' => $class['name'],
                'start_node_id' => $startNodes[$index],
                'base_str' => is_numeric($class['base_str'] ?? null) ? (int) $class['base_str'] : 0,
                'base_dex' => is_numeric($class['base_dex'] ?? null) ? (int) $class['base_dex'] : 0,
                'base_int' => is_numeric($class['base_int'] ?? null) ? (int) $class['base_int'] : 0,
                'ascendancies' => $this->ascendancies($class['ascendancies'] ?? []),
            ];
        }

        return $classes;
    }

    /**
     * @param array<string, array<string, mixed>> $rawNodes
     *
     * @return array<int, string> class index => passive id of its start node
     */
    private function startNodesByClassIndex(array $rawNodes): array
    {
        $starts = [];

        foreach ($rawNodes as $node) {
            $id = $node['id'] ?? null;
            if (!\is_string($id) || !\is_array($node['classStartIndex'] ?? null)) {
                continue;
            }

            foreach ($node['classStartIndex'] as $index) {
                if (\is_int($index)) {
                    $starts[$index] = $id;
                }
            }
        }

        return $starts;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function ascendancies(mixed $raw): array
    {
        $ascendancies = [];

        foreach ((array) $raw as $ascendancy) {
            if (\is_array($ascendancy) && \is_string($ascendancy['id'] ?? null) && \is_string($ascendancy['name'] ?? null)) {
                $ascendancies[] = ['id' => $ascendancy['id'], 'name' => $ascendancy['name']];
            }
        }

        return $ascendancies;
    }

    /**
     * Resolves a list of upstream tree-keys (integers) to stored passive ids
     * through the allocatable map. A key that does not resolve to an
     * allocatable node is dropped rather than kept as a dangling reference.
     *
     * @param array<string, string> $allocatable tree-key => stored id
     *
     * @return list<string>
     */
    private function resolveKeys(mixed $keys, array $allocatable): array
    {
        $resolved = [];

        foreach ((array) (\is_array($keys) ? $keys : []) as $key) {
            $id = $allocatable[(string) (\is_scalar($key) ? $key : '')] ?? null;
            if (\is_string($id)) {
                $resolved[] = $id;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $allocatable
     *
     * @return array{nodes: list<string>, ascendancy: string|null}|null
     */
    private function unlockConstraint(mixed $raw, array $allocatable): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $nodes = $this->resolveKeys($raw['nodes'] ?? null, $allocatable);

        if ([] === $nodes) {
            return null;
        }

        return [
            'nodes' => $nodes,
            'ascendancy' => \is_string($raw['ascendancy'] ?? null) ? $raw['ascendancy'] : null,
        ];
    }
}
