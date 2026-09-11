<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An equippable base item. Gems, currency and everything else the dump calls an
 * item are filtered out during sync.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_base_item')]
#[ORM\Index(name: 'idx_base_class', columns: ['item_class'])]
#[ORM\Index(name: 'idx_base_name', columns: ['name'])]
class CatalogBaseItem
{
    #[ORM\Id]
    #[ORM\Column(length: 190)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'item_class', length: 64)]
    private string $itemClass;

    #[ORM\Column]
    private int $dropLevel = 0;

    #[ORM\Column]
    private int $inventoryWidth = 1;

    #[ORM\Column]
    private int $inventoryHeight = 1;

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

    public function getItemClass(): string
    {
        return $this->itemClass;
    }

    public function getDropLevel(): int
    {
        return $this->dropLevel;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
