<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\SetHeaderField;
use App\Build\Edit\InvalidEditCommand;
use App\Entity\Build;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Header fields come in three kinds. Five of them are part of the Build
 * Planner format and reach the exported file through the entity's columns;
 * four are ours alone and must never leave the database; game_version is
 * neither — it names which catalog the build is read against and lives on
 * the entity without entering BuildDocument. Nothing else may be set.
 */
final class HeaderEditHandler
{
    public function __construct(private readonly BuildEditor $builds)
    {
    }

    #[AsMessageHandler]
    public function set(SetHeaderField $command): void
    {
        $payload = ['field' => $command->field, 'value' => $command->value];

        $this->builds->apply($command->buildId, 'header.set', $payload, function (Build $build) use ($command): void {
            $value = $command->value;
            $optional = '' === $value ? null : $value;

            match ($command->field) {
                'name' => $build->setName('' === $value ? 'Unnamed build' : $value),
                'author' => $build->setAuthor($optional),
                'link' => $build->setLink($optional),
                'description' => $build->setDescription($optional),
                'ascendancy' => $build->setAscendancyKey($optional),
                'class_key' => $build->setClassKey($optional),
                'archetype_key' => $build->setArchetypeKey($optional),
                'note' => $build->setNote($optional),
                'target_level' => $build->setTargetLevel($this->level($value)),
                'game_version' => $build->setGameVersion('' === $value ? throw InvalidEditCommand::noSuchEntry('game version') : $value),
                default => throw InvalidEditCommand::noSuchEntry('header field "'.$command->field.'"'),
            };
        });
    }

    private function level(string $value): ?int
    {
        if ('' === $value) {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 1 || (int) $value > 100) {
            throw InvalidEditCommand::outOfRange('A target level');
        }

        return (int) $value;
    }
}
