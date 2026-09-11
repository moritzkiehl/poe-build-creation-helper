<?php

declare(strict_types=1);

namespace App\Build\Edit\Command;

use App\Build\Edit\EditCommand;

final readonly class SetSupportInterval implements EditCommand
{
    public function __construct(public int $buildId, public int $skillIndex, public string $supportId, public int $from, public int $to)
    {
    }
}
