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
     * Planning fields. The Build Planner format has no room for them, so they
     * are columns here and never enter `document` or `BuildDocument` — an
     * export must carry only fields GGG documents for version 1.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $classKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetLevel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $archetypeKey = null;

    /**
     * Passives the player declared as carrying an Instilled Modifier. App-only,
     * like the planning fields above: the `.build` format has no way to say a
     * passive was granted by an amulet rather than allocated, and the game does
     * not need telling — it reads the amulet.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $instilledPassives = [];

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
        $this->touch();
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

    public function setGameVersion(string $gameVersion): void
    {
        $this->gameVersion = $gameVersion;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
        $this->touch();
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): void
    {
        $this->link = $link;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getAscendancyKey(): ?string
    {
        return $this->ascendancyKey;
    }

    public function setAscendancyKey(?string $ascendancyKey): void
    {
        $this->ascendancyKey = $ascendancyKey;
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getClassKey(): ?string
    {
        return $this->classKey;
    }

    public function setClassKey(?string $classKey): void
    {
        $this->classKey = $classKey;
        $this->touch();
    }

    public function getTargetLevel(): ?int
    {
        return $this->targetLevel;
    }

    public function setTargetLevel(?int $targetLevel): void
    {
        $this->targetLevel = $targetLevel;
        $this->touch();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
        $this->touch();
    }

    public function getArchetypeKey(): ?string
    {
        return $this->archetypeKey;
    }

    public function setArchetypeKey(?string $archetypeKey): void
    {
        $this->archetypeKey = $archetypeKey;
        $this->touch();
    }

    /** @return list<string> */
    public function getInstilledPassives(): array
    {
        return $this->instilledPassives;
    }

    public function addInstilledPassive(string $id): void
    {
        if (\in_array($id, $this->instilledPassives, true)) {
            return;
        }

        $this->instilledPassives[] = $id;
        $this->touch();
    }

    public function removeInstilledPassive(string $id): void
    {
        $remaining = array_values(array_filter($this->instilledPassives, static fn (string $each): bool => $each !== $id));

        if ($remaining === $this->instilledPassives) {
            return;
        }

        $this->instilledPassives = $remaining;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
