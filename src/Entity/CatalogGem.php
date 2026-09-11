<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A skill, support or spirit gem, keyed by the `Metadata/Items/Gem...` path the
 * `.build` format references. Paths are stored exactly as they arrive: the game
 * itself writes both the singular and plural prefix.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_gem')]
#[ORM\Index(name: 'idx_gem_kind', columns: ['kind'])]
#[ORM\Index(name: 'idx_gem_name', columns: ['name'])]
class CatalogGem
{
    #[ORM\Id]
    #[ORM\Column(length: 190)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    /** active | support | spirit */
    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $primaryAttribute = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $icon = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getPrimaryAttribute(): ?string
    {
        return $this->primaryAttribute;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
