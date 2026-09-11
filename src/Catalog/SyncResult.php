<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class SyncResult
{
    public function __construct(
        public bool $ok,
        public string $status,
        public int $count = 0,
        public ?string $error = null,
    ) {
    }
}
