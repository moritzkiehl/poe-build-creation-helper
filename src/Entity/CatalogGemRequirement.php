<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a support gem may be socketed into, expressed in the canonical terms the
 * `support_text` markup names.
 *
 * `origin` is the honest part. A parsed row comes from prose and is right about
 * 85% of the time, so findings derived from it are warnings; a curated row comes
 * from `support_requirements.yaml` and may carry any severity. `clause` keeps
 * the sentence the row was read from, so a wrong extraction can be reviewed
 * instead of guessed at.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_gem_requirement')]
#[ORM\Index(name: 'idx_requirement_gem', columns: ['gem_id'])]
class CatalogGemRequirement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'gem_id', length: 190)]
    private string $gemId;

    #[ORM\Column(length: 128)]
    private string $term;

    /** requires | excludes */
    #[ORM\Column(length: 16)]
    private string $mode;

    /** parsed | curated */
    #[ORM\Column(length: 16)]
    private string $origin;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clause = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGemId(): string
    {
        return $this->gemId;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getClause(): ?string
    {
        return $this->clause;
    }
}
