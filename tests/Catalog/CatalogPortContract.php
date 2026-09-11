<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogPort;

/**
 * The expectations every CatalogPort must meet, run against each implementation.
 *
 * The fake the rule tests use and the one backed by Doctrine answer the same
 * questions the same way, or a rule that passes in a unit test means nothing.
 */
trait CatalogPortContract
{
    abstract private function catalog(): CatalogPort;

    public function testAPopulatedCatalogReportsWhatItWasBuiltFrom(): void
    {
        $state = $this->catalog()->state();

        self::assertNotNull($state);
        self::assertSame('0.5.5', $state->gameVersion);
    }

    public function testAnUnknownPassiveIsNotSilentlyAccepted(): void
    {
        self::assertNull($this->catalog()->passive('nosuchnode1'));
        self::assertNotNull($this->catalog()->passive('strength89'));
    }

    public function testAPassiveKnowsItsKindAndAscendancy(): void
    {
        $passive = $this->catalog()->passive('AscendancyTitan1');

        self::assertNotNull($passive);
        self::assertSame('notable', $passive->kind);
        self::assertSame('Titan1', $passive->ascendancyKey);
    }

    public function testNeighboursAnswerBothDirectionsOfAnEdge(): void
    {
        $catalog = $this->catalog();

        self::assertContains('melee22_', $catalog->neighbours('strength89'));
        self::assertContains('strength89', $catalog->neighbours('melee22_'));
    }

    public function testAGemKnowsItsKindAndTags(): void
    {
        $gem = $this->catalog()->gem('Metadata/Items/Gems/SkillGemEarthquake');

        self::assertNotNull($gem);
        self::assertSame('active', $gem->kind);
        self::assertContains('melee', $gem->tags);
    }

    public function testSupportRequirementsCarryTheirOrigin(): void
    {
        $requirements = $this->catalog()->supportRequirements('Metadata/Items/Gem/SupportGemAbidingHex');

        self::assertCount(1, $requirements);
        self::assertSame('Curse', $requirements[0]->term);
        self::assertSame('requires', $requirements[0]->mode);
        self::assertSame('parsed', $requirements[0]->origin);
    }

    public function testRecommendedSupportsComeBackInOrder(): void
    {
        self::assertSame(
            ['Metadata/Items/Gems/SupportGemConcentratedEffect', 'Metadata/Items/Gem/SupportGemMartialTempo'],
            $this->catalog()->recommendedSupports('Metadata/Items/Gems/SkillGemEarthquake'),
        );
    }

    /**
     * A name can belong to several uniques, so the port never answers with one.
     */
    public function testUniquesAreLookedUpByNameAndMayBeSeveral(): void
    {
        $catalog = $this->catalog();

        self::assertCount(1, $catalog->uniquesNamed('Astramentis'));
        self::assertCount(2, $catalog->uniquesNamed('Grand Spectrum'));
        self::assertSame([], $catalog->uniquesNamed('No Such Unique'));
    }
}
