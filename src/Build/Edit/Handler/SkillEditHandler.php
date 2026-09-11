<?php

declare(strict_types=1);

namespace App\Build\Edit\Handler;

use App\Build\Edit\BuildEditor;
use App\Build\Edit\Command\AddSkill;
use App\Build\Edit\Command\AddSupport;
use App\Build\Edit\Command\RemoveSkill;
use App\Build\Edit\Command\RemoveSupport;
use App\Build\Edit\Command\SetSkillInterval;
use App\Build\Edit\Command\SetSupportInterval;
use App\Build\Edit\DocumentEditor;
use App\Entity\Build;
use App\Interchange\BuildDocument;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SkillEditHandler
{
    public function __construct(
        private readonly BuildEditor $builds,
        private readonly DocumentEditor $documents,
    ) {
    }

    #[AsMessageHandler]
    public function add(AddSkill $command): void
    {
        $this->builds->apply($command->buildId, 'skill.add', ['gem_id' => $command->gemId], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->addSkill($d, $command->gemId));
        });
    }

    #[AsMessageHandler]
    public function remove(RemoveSkill $command): void
    {
        $this->builds->apply($command->buildId, 'skill.remove', ['index' => $command->index], function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->removeSkill($d, $command->index));
        });
    }

    #[AsMessageHandler]
    public function setInterval(SetSkillInterval $command): void
    {
        $payload = ['index' => $command->index, 'from' => $command->from, 'to' => $command->to];

        $this->builds->apply($command->buildId, 'skill.interval', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setSkillLevelInterval($d, $command->index, $command->from, $command->to));
        });
    }

    #[AsMessageHandler]
    public function addSupport(AddSupport $command): void
    {
        $payload = ['skill_index' => $command->skillIndex, 'support_id' => $command->supportId];

        $this->builds->apply($command->buildId, 'support.add', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->addSupport($d, $command->skillIndex, $command->supportId));
        });
    }

    #[AsMessageHandler]
    public function removeSupport(RemoveSupport $command): void
    {
        $payload = ['skill_index' => $command->skillIndex, 'support_id' => $command->supportId];

        $this->builds->apply($command->buildId, 'support.remove', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->removeSupport($d, $command->skillIndex, $command->supportId));
        });
    }

    #[AsMessageHandler]
    public function setSupportInterval(SetSupportInterval $command): void
    {
        $payload = ['skill_index' => $command->skillIndex, 'support_id' => $command->supportId, 'from' => $command->from, 'to' => $command->to];

        $this->builds->apply($command->buildId, 'support.interval', $payload, function (Build $build) use ($command): void {
            $this->builds->document($build, fn (BuildDocument $d): BuildDocument => $this->documents->setSupportLevelInterval($d, $command->skillIndex, $command->supportId, $command->from, $command->to));
        });
    }
}
