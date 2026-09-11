<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A tag a mod can actually roll on. Weights of zero mean it cannot, and are not
 * stored at all.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_mod_spawn_tag')]
#[ORM\Index(name: 'idx_spawn_tag', columns: ['tag'])]
class CatalogModSpawnTag
{
    #[ORM\Id]
    #[ORM\Column(name: 'mod_id', length: 190)]
    private string $modId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $tag;

    #[ORM\Column]
    private int $weight = 0;

    public function getModId(): string
    {
        return $this->modId;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }
}
