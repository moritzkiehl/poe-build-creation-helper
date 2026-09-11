<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\View\CatalogState;
use App\Catalog\View\GemInfo;
use App\Catalog\View\PassiveInfo;
use App\Catalog\View\RequirementInfo;
use App\Catalog\View\UniqueInfo;

/**
 * A catalog held in arrays.
 *
 * Two jobs. It is the fake the rule tests run against, so a rule can be exercised
 * without a database. And an empty one is the honest answer when nothing has been
 * synced: `isAvailable()` is false and every lookup misses, which is exactly what
 * the application must survive.
 */
final class InMemoryCatalog implements CatalogPort
{
    /** @var array<string, PassiveInfo> */
    private array $passives = [];

    /** @var array<string, list<string>> */
    private array $edges = [];

    /** @var array<string, GemInfo> */
    private array $gems = [];

    /** @var array<string, list<RequirementInfo>> */
    private array $requirements = [];

    /** @var array<string, list<string>> */
    private array $recommended = [];

    /** @var list<UniqueInfo> */
    private array $uniques = [];

    public function __construct(private readonly ?CatalogState $state = null)
    {
    }

    public function isAvailable(): bool
    {
        return null !== $this->state;
    }

    public function state(): ?CatalogState
    {
        return $this->state;
    }

    public function passive(string $id): ?PassiveInfo
    {
        return $this->passives[$id] ?? null;
    }

    public function neighbours(string $id): array
    {
        return $this->edges[$id] ?? [];
    }

    public function gem(string $id): ?GemInfo
    {
        return $this->gems[$id] ?? null;
    }

    public function supportRequirements(string $supportId): array
    {
        return $this->requirements[$supportId] ?? [];
    }

    public function recommendedSupports(string $gemId): array
    {
        return $this->recommended[$gemId] ?? [];
    }

    public function uniquesNamed(string $name): array
    {
        return array_values(array_filter($this->uniques, static fn (UniqueInfo $u): bool => $u->name === $name));
    }

    public function withPassive(PassiveInfo $passive): self
    {
        $this->passives[$passive->id] = $passive;

        return $this;
    }

    public function withEdge(string $from, string $to): self
    {
        $this->edges[$from][] = $to;
        $this->edges[$to][] = $from;

        return $this;
    }

    public function withGem(GemInfo $gem): self
    {
        $this->gems[$gem->id] = $gem;

        return $this;
    }

    public function withRequirement(string $supportId, RequirementInfo $requirement): self
    {
        $this->requirements[$supportId][] = $requirement;

        return $this;
    }

    /**
     * @param list<string> $supportIds
     */
    public function withRecommendedSupports(string $gemId, array $supportIds): self
    {
        $this->recommended[$gemId] = $supportIds;

        return $this;
    }

    public function withUnique(UniqueInfo $unique): self
    {
        $this->uniques[] = $unique;

        return $this;
    }

    /**
     * A small, realistic catalog for tests: real identifiers, invented content.
     */
    public static function withFixtures(): self
    {
        $catalog = new self(new CatalogState('0.5.5', new \DateTimeImmutable('2026-09-11 12:00:00')));

        $catalog
            ->withPassive(new PassiveInfo('strength89', 'Attribute', 'small'))
            ->withPassive(new PassiveInfo('melee22_', 'Brutal Blows', 'notable'))
            ->withPassive(new PassiveInfo('AscendancyTitan1', 'Titan Notable', 'notable', 'Titan1'))
            ->withEdge('strength89', 'melee22_')
            ->withGem(new GemInfo('Metadata/Items/Gems/SkillGemEarthquake', 'Earthquake', 'active', ['attack', 'melee', 'slam'], 'strength'))
            ->withGem(new GemInfo('Metadata/Items/Gem/SupportGemAbidingHex', 'Abiding Hex', 'support', ['support', 'curse'], 'intelligence'))
            ->withRequirement('Metadata/Items/Gem/SupportGemAbidingHex', new RequirementInfo('Curse', 'requires', 'parsed', 'Supports [Curse] Skills you cast yourself.'))
            ->withRecommendedSupports('Metadata/Items/Gems/SkillGemEarthquake', [
                'Metadata/Items/Gems/SupportGemConcentratedEffect',
                'Metadata/Items/Gem/SupportGemMartialTempo',
            ])
            ->withUnique(new UniqueInfo('1', 'Astramentis', 'Amulet'))
            ->withUnique(new UniqueInfo('2', 'Grand Spectrum', 'Jewel'))
            ->withUnique(new UniqueInfo('3', 'Grand Spectrum', 'Jewel'));

        return $catalog;
    }
}
