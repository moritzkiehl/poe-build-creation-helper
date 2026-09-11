<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class NormalizedMods
{
    /**
     * @param list<array{id: string, name: string, text: string, generation_type: string, required_level: int}> $mods
     * @param list<array{mod_id: string, tag: string, weight: int}>                                             $spawnTags
     */
    public function __construct(
        public array $mods,
        public array $spawnTags,
    ) {
    }
}
