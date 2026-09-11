<?php

declare(strict_types=1);

namespace App\Build\Edit\Command;

use App\Build\Edit\EditCommand;

final readonly class SetSlot implements EditCommand
{
    public function __construct(
        public int $buildId,
        public string $inventoryId,
        public ?string $uniqueName,
        public int $from,
        public int $to,
        public string $additionalText,
    ) {
    }
}
