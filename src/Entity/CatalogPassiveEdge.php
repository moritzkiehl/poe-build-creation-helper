<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One direction of a connection between two passives. Tree connectivity is the
 * only thing `passive.disconnected` needs, and it needs it fast.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_passive_edge')]
#[ORM\Index(name: 'idx_edge_to', columns: ['to_id'])]
class CatalogPassiveEdge
{
    #[ORM\Id]
    #[ORM\Column(name: 'from_id', length: 128)]
    private string $fromId;

    #[ORM\Id]
    #[ORM\Column(name: 'to_id', length: 128)]
    private string $toId;

    public function getFromId(): string
    {
        return $this->fromId;
    }

    public function getToId(): string
    {
        return $this->toId;
    }
}
