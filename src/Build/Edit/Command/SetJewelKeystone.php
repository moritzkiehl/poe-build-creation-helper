<?php

declare(strict_types=1);

namespace App\Build\Edit\Command;

use App\Build\Edit\EditCommand;

final readonly class SetJewelKeystone implements EditCommand
{
    public function __construct(public int $buildId, public ?string $keystoneId)
    {
    }
}
