<?php

declare(strict_types=1);

namespace App\Entity;

use App\Interchange\BuildDocument;
use App\Repository\BuildRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A build sketch.
 *
 * The scalars the Build Planner format documents are columns, so they can be
 * searched and shown without decoding anything. The three collections live in
 * one JSON document, because the aggregate is always read whole and written
 * whole and there is no query for "which builds use skill X".
 *
 * There is no owner: the share slug is the address and the edit token is the
 * permission. The token is never stored, only its hash.
 */
#[ORM\Entity(repositoryClass: BuildRepository::class)]
#[ORM\Table(name: 'build')]
#[ORM\HasLifecycleCallbacks]
class Build
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $shareSlug;

    #[ORM\Column(length: 64)]
    private string $editTokenHash;

    /**
     * Only set on a build this process just created, so the token can be shown
     * once. A build loaded from the database never has it.
     */
    private ?string $editToken = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ascendancyKey = null;

    #[ORM\Column(length: 32)]
    private string $gameVersion;

    /**
     * @var array{passives: list<array<string, mixed>>, skills: list<array<string, mixed>>, inventory_slots: list<array<string, mixed>>}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $document;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(BuildDocument $document, string $gameVersion, string $shareSlug, string $editTokenHash, ?string $editToken = null)
    {
        $this->shareSlug = $shareSlug;
        $this->editTokenHash = $editTokenHash;
        $this->editToken = $editToken;
        $this->gameVersion = $gameVersion;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->applyDocument($document);
    }

    public function applyDocument(BuildDocument $document): void
    {
        $this->name = $document->name;
        $this->author = $document->author;
        $this->link = $document->link;
        $this->description = $document->description;
        $this->ascendancyKey = $document->ascendancy;
        $this->document = [
            'passives' => $document->passives,
            'skills' => $document->skills,
            'inventory_slots' => $document->inventorySlots,
        ];
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function toDocument(): BuildDocument
    {
        return new BuildDocument(
            name: $this->name,
            author: $this->author,
            link: $this->link,
            description: $this->description,
            ascendancy: $this->ascendancyKey,
            passives: $this->document['passives'],
            skills: $this->document['skills'],
            inventorySlots: $this->document['inventory_slots'],
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShareSlug(): string
    {
        return $this->shareSlug;
    }

    public function getEditTokenHash(): string
    {
        return $this->editTokenHash;
    }

    /**
     * The clear token, available only on a freshly created build.
     */
    public function getEditToken(): ?string
    {
        return $this->editToken;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGameVersion(): string
    {
        return $this->gameVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
