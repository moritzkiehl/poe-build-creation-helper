<?php

declare(strict_types=1);

namespace App\Interchange;

/**
 * A build in the shape of GGG's Build Planner format, version 1.
 *
 * Only the fields the format documents are named here. The three collections
 * are carried as decoded data rather than modelled: the shape of
 * `level_interval` is not documented anywhere we can check, and inventing one
 * would make an export the game rejects. Modelling them is iteration 3 work,
 * once the shapes are confirmed — see the proof list in the design document.
 */
final readonly class BuildDocument
{
    /**
     * @param list<array<string, mixed>> $passives
     * @param list<array<string, mixed>> $skills
     * @param list<array<string, mixed>> $inventorySlots
     */
    public function __construct(
        public string $name,
        public ?string $author = null,
        public ?string $link = null,
        public ?string $description = null,
        public ?string $ascendancy = null,
        public array $passives = [],
        public array $skills = [],
        public array $inventorySlots = [],
    ) {
    }
}
