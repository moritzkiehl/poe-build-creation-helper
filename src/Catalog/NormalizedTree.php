<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The passive tree reduced to what the application needs: allocatable nodes and
 * the edges between them.
 *
 * @phpstan-type PassiveNode array{id: string, name: string, kind: string, ascendancy_key: string|null, pos_x: float, pos_y: float, stats: list<string>}
 */
final readonly class NormalizedTree
{
    /**
     * @param list<PassiveNode>           $nodes
     * @param list<array{string, string}> $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public ?string $treeVariant = null,
    ) {
    }
}
