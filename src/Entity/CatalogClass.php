<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of the game's twelve classes, keyed by the name the tree export writes.
 *
 * `startNodeId` is what the editor centres the tree on. Six nodes serve all
 * twelve classes — two classes always share one physical start position — so
 * this is not a unique column.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'catalog_class')]
class CatalogClass
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 128)]
    private string $startNodeId;

    #[ORM\Column]
    private int $baseStr = 0;

    #[ORM\Column]
    private int $baseDex = 0;

    #[ORM\Column]
    private int $baseInt = 0;

    /** @var list<array{id: string, name: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $ascendancies = [];

    public function getId(): string
    {
        return $this->id;
    }

    public function getStartNodeId(): string
    {
        return $this->startNodeId;
    }

    public function getBaseStr(): int
    {
        return $this->baseStr;
    }

    public function getBaseDex(): int
    {
        return $this->baseDex;
    }

    public function getBaseInt(): int
    {
        return $this->baseInt;
    }

    /** @return list<array{id: string, name: string}> */
    public function getAscendancies(): array
    {
        return $this->ascendancies;
    }
}
