<?php

declare(strict_types=1);

namespace App\Catalog\View;

final readonly class GemInfo
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public array $tags = [],
        public ?string $primaryAttribute = null,
    ) {
    }
}
