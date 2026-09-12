<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BuildEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One edit, with the whole editable state as it stood afterwards.
 *
 * The snapshot is stored rather than replayed. A build document is small, and
 * storing it whole means reverting is a copy instead of a replay engine.
 * Reverting appends a new event of its own: the log is never rewritten.
 *
 * @phpstan-type Snapshot array{document: array<string, mixed>, header: array{class_key: string|null, target_level: int|null, note: string|null, archetype_key: string|null}}
 */
#[ORM\Entity(repositoryClass: BuildEventRepository::class)]
#[ORM\Table(name: 'build_event')]
#[ORM\Index(name: 'idx_build_event_prune', columns: ['created_at', 'is_named_snapshot'])]
class BuildEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Build $build;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 32)]
    private string $action;

    /** @var array<string, scalar|list<string>|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    /** @var Snapshot */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot;

    #[ORM\Column]
    private bool $isNamedSnapshot = false;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $snapshotName = null;

    /**
     * @param array<string, scalar|list<string>|null> $payload
     * @param Snapshot                                $snapshot
     */
    public function __construct(Build $build, string $action, array $payload, array $snapshot, ?string $snapshotName = null)
    {
        $this->build = $build;
        $this->action = $action;
        $this->payload = $payload;
        $this->snapshot = $snapshot;
        $this->snapshotName = $snapshotName;
        $this->isNamedSnapshot = null !== $snapshotName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuild(): Build
    {
        return $this->build;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, scalar|list<string>|null> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @return Snapshot */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function isNamedSnapshot(): bool
    {
        return $this->isNamedSnapshot;
    }

    public function getSnapshotName(): ?string
    {
        return $this->snapshotName;
    }
}
