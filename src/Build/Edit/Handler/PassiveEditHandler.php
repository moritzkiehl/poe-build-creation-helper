<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\DeallocatePassive;
use App\Build\Edit\Command\SetAllPassiveIntervals;
use App\Build\Edit\Command\SetPassiveInterval;
use App\Build\Edit\DocumentEditor;
use App\Build\Edit\InvalidEditCommand;
use App\Build\Tree\Allocation;
use App\Build\Tree\AllocationRules;
use App\Build\Tree\TreeContextFactory;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PassiveEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly DocumentEditor $documents,
        private readonly AllocationRules $rules,
        private readonly TreeContextFactory $contexts,
    ) {
    }

    #[AsMessageHandler]
    public function allocate(AllocatePassive $command): void
    {
        $payload = ['id' => $command->id, 'set' => $command->set->toWire()];

        $this->builds->apply($command->buildId, 'passive.allocate', $payload, function (Build $build) use ($command): void {
            $document = $build->toDocument();
            $context = $this->contexts->of($build);

            if (!$this->rules->mayAllocate(Allocation::of($document), $context, $command->id, $command->set)) {
                throw InvalidEditCommand::illegalAllocation($command->id);
            }

            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->allocatePassive($d, $command->id, $command->set));
        });
    }

    /**
     * The cascade has to be computed before `apply()` is called: the payload
     * that gets recorded needs `also` up front, and by the time `apply()`'s
     * own closure runs, that payload has already been handed over. Doctrine's
     * identity map returns the same `Build` instance for both the lookup here
     * and the one inside `apply()`, so the cascade computed against it here
     * cannot go stale before the mutation below applies it.
     *
     * `also` is only what *becomes* illegal *as a result of* this removal.
     * This app models neither socket-radius jewels nor every unlock path, so
     * a real imported build can already carry passives the rules cannot
     * justify. Those are pinned: they stay exactly as illegal as they already
     * were rather than being swept on an unrelated, or even a no-op,
     * deallocation — and because they stay, whatever still routes through
     * one, or is gated by one, stays with them.
     */
    #[AsMessageHandler]
    public function deallocate(DeallocatePassive $command): void
    {
        $build = $this->builds->find($command->buildId);
        $context = $this->contexts->of($build);
        $allocation = Allocation::of($build->toDocument());

        $alreadyIllegal = $this->rules->illegalAfter($allocation, $context);
        $also = $this->rules->illegalAfter($allocation->without($command->id), $context, $alreadyIllegal);

        $this->builds->apply($command->buildId, 'passive.deallocate', ['id' => $command->id, 'also' => $also], function (Build $build) use ($command, $also): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->deallocatePassives($d, [$command->id, ...$also]));
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

    #[AsMessageHandler]
    public function setAllIntervals(SetAllPassiveIntervals $command): void
    {
        $payload = ['from' => $command->from, 'to' => $command->to];

        $this->builds->apply($command->buildId, 'passive.interval_all', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setAllPassiveLevelIntervals($d, $command->from, $command->to));
        });
    }
}
