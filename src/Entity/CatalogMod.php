<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A craftable prefix or suffix.
 *
 * This can never be checked against a build: the `.build` format carries no rare
 * items and no modifiers. It exists so the catalog can answer what may roll on a
 * base, and so curated rules can point at a specific mod.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_mod')]
#[ORM\Index(name: 'idx_mod_type', columns: ['generation_type'])]
class CatalogMod
{
    #[ORM\Id]
    #[ORM\Column(length: 190)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    /** prefix | suffix */
    #[ORM\Column(name: 'generation_type', length: 16)]
    private string $generationType;

    #[ORM\Column]
    private int $requiredLevel = 0;

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getGenerationType(): string
    {
        return $this->generationType;
    }

    public function getRequiredLevel(): int
    {
        return $this->requiredLevel;
    }
}
