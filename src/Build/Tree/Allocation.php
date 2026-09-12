<?php

declare(strict_types=1);

namespace App\Build\Tree;

use App\Interchange\BuildDocument;

/**
 * The build's allocated passives, each with the weapon set it belongs to.
 *
 * A node id appears at most once — measured across the real corpus, no file
 * allocates the same id twice — so a map keyed by id loses nothing.
 */
final readonly class Allocation
{
    /**
     * @param array<string, WeaponSet> $byId
     */
    public function __construct(private array $byId)
    {
    }

    public static function of(BuildDocument $document): self
    {
        $byId = [];

        foreach ($document->passives as $passive) {
            $id = $passive['id'] ?? null;

            if (\is_string($id) && '' !== $id) {
                $byId[$id] = WeaponSet::fromWire($passive['weapon_set'] ?? null);
            }
        }

        return new self($byId);
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function setOf(string $id): ?WeaponSet
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    /**
     * What a node in `$set` may route through: its own set plus the shared
     * nodes, never the other set. This is the per-set connectivity rule.
     *
     * @return list<string>
     */
    public function idsVisibleTo(WeaponSet $set): array
    {
        $visible = [];

        foreach ($this->byId as $id => $nodeSet) {
            if (WeaponSet::Shared === $nodeSet || $nodeSet === $set) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    public function without(string $id): self
    {
        $byId = $this->byId;
        unset($byId[$id]);

        return new self($byId);
    }
}
