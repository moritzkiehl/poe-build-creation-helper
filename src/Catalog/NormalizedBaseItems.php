<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class NormalizedBaseItems
{
    /**
     * @param list<array{id: string, name: string, item_class: string, drop_level: int, inventory_width: int, inventory_height: int, icon: string|null}> $items
     * @param list<array{base_item_id: string, tag: string}>                                                                                             $tags
     */
    public function __construct(
        public array $items,
        public array $tags,
    ) {
    }
}
