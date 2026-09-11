<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A passive tree node, keyed by the id the `.build` format references.
 *
 * Written in bulk by the sync and only ever read by the application, so it
 * carries no behaviour.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_passive')]
#[ORM\Index(name: 'idx_passive_kind', columns: ['kind'])]
#[ORM\Index(name: 'idx_passive_ascendancy', columns: ['ascendancy_key'])]
class CatalogPassive
{
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ascendancyKey = null;

    #[ORM\Column]
    private float $posX = 0.0;

    #[ORM\Column]
    private float $posY = 0.0;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $stats = [];

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

    public function getAscendancyKey(): ?string
    {
        return $this->ascendancyKey;
    }

    public function getPosX(): float
    {
        return $this->posX;
    }

    public function getPosY(): float
    {
        return $this->posY;
    }

    /** @return list<string> */
    public function getStats(): array
    {
        return $this->stats;
    }
}
