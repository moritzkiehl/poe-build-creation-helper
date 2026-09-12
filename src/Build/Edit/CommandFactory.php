<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Build\Edit\Command\AddInstilled;
use App\Build\Edit\Command\AddSkill;
use App\Build\Edit\Command\AddSupport;
use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\ClearSlot;
use App\Build\Edit\Command\CreateSnapshot;
use App\Build\Edit\Command\DeallocatePassive;
use App\Build\Edit\Command\RemoveInstilled;
use App\Build\Edit\Command\RemoveSkill;
use App\Build\Edit\Command\RemoveSupport;
use App\Build\Edit\Command\Revert;
use App\Build\Edit\Command\SetAllPassiveIntervals;
use App\Build\Edit\Command\SetHeaderField;
use App\Build\Edit\Command\SetPassiveInterval;
use App\Build\Edit\Command\SetSkillInterval;
use App\Build\Edit\Command\SetSkillIntervalCascading;
use App\Build\Edit\Command\SetSlot;
use App\Build\Edit\Command\SetSupportInterval;
use App\Build\Tree\WeaponSet;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Turns one posted form into one command.
 *
 * A match rather than a registry: a dozen cases read faster in one place than
 * spread over a dozen tagged services, and every unknown action is refused
 * here rather than somewhere deeper.
 */
final class CommandFactory
{
    /**
     * @param InputBag<string|int|float|bool|null> $payload
     */
    public function fromRequest(int $buildId, string $action, InputBag $payload): EditCommand
    {
        return match ($action) {
            'header.set' => new SetHeaderField($buildId, $this->string($payload, 'field'), $this->string($payload, 'value', required: false)),
            'passive.allocate' => new AllocatePassive($buildId, $this->string($payload, 'id'), WeaponSet::fromWire($this->optionalString($payload, 'set'))),
            'passive.deallocate' => new DeallocatePassive($buildId, $this->string($payload, 'id')),
            'passive.interval' => new SetPassiveInterval($buildId, $this->string($payload, 'id'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'passive.interval_all' => new SetAllPassiveIntervals($buildId, $this->int($payload, 'from'), $this->int($payload, 'to')),
            'skill.add' => new AddSkill($buildId, $this->string($payload, 'gem_id')),
            'skill.remove' => new RemoveSkill($buildId, $this->int($payload, 'index')),
            'skill.interval' => new SetSkillInterval($buildId, $this->int($payload, 'index'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'skill.interval_cascade' => new SetSkillIntervalCascading($buildId, $this->int($payload, 'index'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'support.add' => new AddSupport($buildId, $this->int($payload, 'skill_index'), $this->string($payload, 'support_id')),
            'support.remove' => new RemoveSupport($buildId, $this->int($payload, 'skill_index'), $this->string($payload, 'support_id')),
            'support.interval' => new SetSupportInterval($buildId, $this->int($payload, 'skill_index'), $this->string($payload, 'support_id'), $this->int($payload, 'from'), $this->int($payload, 'to')),
            'slot.set' => new SetSlot($buildId, $this->string($payload, 'inventory_id'), $this->optionalString($payload, 'unique_name'), $this->int($payload, 'from'), $this->int($payload, 'to'), $this->string($payload, 'additional_text', required: false)),
            'slot.clear' => new ClearSlot($buildId, $this->string($payload, 'inventory_id')),
            'snapshot.create' => new CreateSnapshot($buildId, $this->string($payload, 'name')),
            'history.revert' => new Revert($buildId, $this->int($payload, 'event_id')),
            'instilled.add' => new AddInstilled($buildId, $this->string($payload, 'id')),
            'instilled.remove' => new RemoveInstilled($buildId, $this->string($payload, 'id')),
            default => throw InvalidEditCommand::unknownAction($action),
        };
    }

    /**
     * @param InputBag<string|int|float|bool|null> $payload
     */
    private function string(InputBag $payload, string $field, bool $required = true): string
    {
        $value = $payload->get($field);

        if (!\is_string($value) || ('' === $value && $required)) {
            throw InvalidEditCommand::noSuchEntry('value for "'.$field.'"');
        }

        return $value;
    }

    /**
     * @param InputBag<string|int|float|bool|null> $payload
     */
    private function optionalString(InputBag $payload, string $field): ?string
    {
        $value = $payload->get($field);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param InputBag<string|int|float|bool|null> $payload
     */
    private function int(InputBag $payload, string $field): int
    {
        $value = $payload->get($field);

        if (!is_numeric($value)) {
            throw InvalidEditCommand::outOfRange('"'.$field.'"');
        }

        return (int) $value;
    }
}
