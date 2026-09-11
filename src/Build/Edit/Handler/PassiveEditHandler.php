<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\DeallocatePassive;
use App\Build\Edit\Command\SetPassiveInterval;
use App\Build\Edit\DocumentEditor;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PassiveEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly DocumentEditor $documents,
    ) {
    }

    #[AsMessageHandler]
    public function allocate(AllocatePassive $command): void
    {
        $this->builds->apply($command->buildId, 'passive.allocate', ['id' => $command->id], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->allocatePassive($d, $command->id));
        });
    }

    #[AsMessageHandler]
    public function deallocate(DeallocatePassive $command): void
    {
        $this->builds->apply($command->buildId, 'passive.deallocate', ['id' => $command->id], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->deallocatePassive($d, $command->id));
        });
    }

    #[AsMessageHandler]
    public function setInterval(SetPassiveInterval $command): void
    {
        $payload = ['id' => $command->id, 'from' => $command->from, 'to' => $command->to];

        $this->builds->apply($command->buildId, 'passive.interval', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setPassiveLevelInterval($d, $command->id, $command->from, $command->to));
        });
    }
}
