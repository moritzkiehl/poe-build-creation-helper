<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One record per sync run, so the data status the interface shows can be
 * evidenced rather than claimed: where it came from, when, which upstream
 * revision, and whether it worked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_sync')]
class CatalogSync
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $source;

    #[ORM\Column]
    private \DateTimeImmutable $ranAt;

    /**
     * Whatever upstream gave us to revalidate with: an ETag where the host
     * sends one, otherwise a Last-Modified date. Opaque on purpose — it is
     * handed straight back in the next conditional request.
     */
    #[ORM\Column(name: 'upstream_revision', length: 255, nullable: true)]
    private ?string $upstreamRevision = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $gameVersion = null;

    /** ok | unchanged | failed */
    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column]
    private int $count = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(string $source, string $status, int $count = 0, ?string $upstreamRevision = null, ?string $error = null, ?string $gameVersion = null)
    {
        $this->source = $source;
        $this->status = $status;
        $this->count = $count;
        $this->upstreamRevision = $upstreamRevision;
        $this->error = $error;
        $this->gameVersion = $gameVersion;
        $this->ranAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getRanAt(): \DateTimeImmutable
    {
        return $this->ranAt;
    }

    public function getUpstreamRevision(): ?string
    {
        return $this->upstreamRevision;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
