<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_gem_tag')]
#[ORM\Index(name: 'idx_gem_tag_tag', columns: ['tag'])]
class CatalogGemTag
{
    #[ORM\Id]
    #[ORM\Column(name: 'gem_id', length: 190)]
    private string $gemId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $tag;

    public function getGemId(): string
    {
        return $this->gemId;
    }

    public function getTag(): string
    {
        return $this->tag;
    }
}
