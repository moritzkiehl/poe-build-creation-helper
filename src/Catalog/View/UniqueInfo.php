<?php

declare(strict_types=1);

namespace App\Catalog\View;

final readonly class UniqueInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public string $itemClass,
    ) {
    }
}
