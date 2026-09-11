<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A unique item: its name, class and size, and nothing about what it does.
 *
 * No published source links a unique to its modifiers, so effects are curated
 * rather than derived. The row keeps RePoE's key rather than the name because
 * several uniques share a name — which is also why `.build`, identifying them by
 * name, can be ambiguous.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_unique')]
#[ORM\Index(name: 'idx_unique_name', columns: ['name'])]
#[ORM\Index(name: 'idx_unique_class', columns: ['item_class'])]
class CatalogUnique
{
    #[ORM\Id]
    #[ORM\Column(length: 190)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'item_class', length: 64)]
    private string $itemClass;

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

    public function getInventoryWidth(): int
    {
        return $this->inventoryWidth;
    }

    public function getInventoryHeight(): int
    {
        return $this->inventoryHeight;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
