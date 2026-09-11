<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The game's own skill-to-support pairing, and the basis for
 * `support.suggestion`. Present on 379 of 505 active gems in 0.5.5, which makes
 * it a far better source than inferring compatibility from tags.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_gem_recommended_support')]
class CatalogGemRecommendedSupport
{
    #[ORM\Id]
    #[ORM\Column(name: 'gem_id', length: 190)]
    private string $gemId;

    #[ORM\Id]
    #[ORM\Column(name: 'support_id', length: 190)]
    private string $supportId;

    #[ORM\Column]
    private int $rank = 0;

    public function getGemId(): string
    {
        return $this->gemId;
    }

    public function getSupportId(): string
    {
        return $this->supportId;
    }

    public function getRank(): int
    {
        return $this->rank;
    }
}
