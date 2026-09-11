<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The passive tree reduced to what the application needs: allocatable nodes and
 * the edges between them.
 *
 * @phpstan-type PassiveNode array{id: string, name: string, kind: string, ascendancy_key: string|null, pos_x: float, pos_y: float, stats: list<string>}
 * @phpstan-type PassiveClass array{id: string, start_node_id: string, base_str: int, base_dex: int, base_int: int, ascendancies: list<array{id: string, name: string}>}
 */
final readonly class NormalizedTree
{
    /**
     * @param list<PassiveNode>           $nodes
     * @param list<array{string, string}> $edges
     * @param list<PassiveClass>          $classes
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $classes = [],
        public ?string $treeVariant = null,
    ) {
    }
}
