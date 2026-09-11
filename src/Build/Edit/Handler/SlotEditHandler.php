<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\ClearSlot;
use App\Build\Edit\Command\SetSlot;
use App\Build\Edit\DocumentEditor;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SlotEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly DocumentEditor $documents,
    ) {
    }

    #[AsMessageHandler]
    public function set(SetSlot $command): void
    {
        $payload = [
            'inventory_id' => $command->inventoryId,
            'unique_name' => $command->uniqueName,
            'from' => $command->from,
            'to' => $command->to,
            'additional_text' => $command->additionalText,
        ];

        $this->builds->apply($command->buildId, 'slot.set', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setInventorySlot($d, $command->inventoryId, $command->uniqueName, $command->from, $command->to, $command->additionalText));
        });
    }

    #[AsMessageHandler]
    public function clear(ClearSlot $command): void
    {
        $this->builds->apply($command->buildId, 'slot.clear', ['inventory_id' => $command->inventoryId], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->clearInventorySlot($d, $command->inventoryId));
        });
    }
}
