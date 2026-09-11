<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The join between a base item and the crafting mods that can roll on it: mod
 * spawn weights are expressed in exactly this tag vocabulary.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_base_item_tag')]
#[ORM\Index(name: 'idx_base_tag_tag', columns: ['tag'])]
class CatalogBaseItemTag
{
    #[ORM\Id]
    #[ORM\Column(name: 'base_item_id', length: 190)]
    private string $baseItemId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $tag;

    public function getBaseItemId(): string
    {
        return $this->baseItemId;
    }

    public function getTag(): string
    {
        return $this->tag;
    }
}
