<?php

declare(strict_types=1);

namespace App\Catalog\View;

final readonly class PassiveInfo
{
    /**
     * @param list<string> $stats
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public ?string $ascendancyKey = null,
        public array $stats = [],
    ) {
    }
}
