<?php

declare(strict_types=1);

namespace App\Build\Edit\Command;

use App\Build\Edit\EditCommand;
use App\Build\Tree\WeaponSet;

final readonly class AllocatePassive implements EditCommand
{
    public function __construct(public int $buildId, public string $id, public WeaponSet $set = WeaponSet::Shared)
    {
    }
}
