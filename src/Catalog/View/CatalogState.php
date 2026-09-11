<?php

declare(strict_types=1);

namespace App\Catalog\View;

/**
 * What the catalog was built from. Every finding carries this, so a player can
 * see which data a check ran against.
 */
final readonly class CatalogState
{
    public function __construct(
        public string $gameVersion,
        public \DateTimeImmutable $syncedAt,
    ) {
    }
}
