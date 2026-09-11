<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\GemNormalizer;
use App\Catalog\NormalizedGems;
use PHPUnit\Framework\TestCase;

/**
 * Shape without content: the fixture mirrors how RePoE writes skill_gems while
 * inventing every identifier and name.
 */
final class GemNormalizerTest extends TestCase
{
    public function testGemsAreKeyedByTheIdentifierTheBuildFormatUses(): void
    {
        self::assertContains('Metadata/Items/Gems/SkillGemSyntheticStrike', array_column($this->normalize()->gems, 'id'));
    }

    /**
     * Real data carries Metadata/Items/Gem and Metadata/Items/Gems side by side,
     * confirmed in game-exported build files. Tidying the prefix would break
     * export into the game.
     */
    public function testBothPathPrefixesSurviveVerbatim(): void
    {
        $ids = array_column($this->normalize()->gems, 'id');

        self::assertContains('Metadata/Items/Gems/SkillGemSyntheticStrike', $ids);
        self::assertContains('Metadata/Items/Gem/SupportGemSyntheticBoost', $ids);
    }

    /**
     * 0.5.5 ships 77 of these. They are marked in the display name, and the
     * marker is bracketed: "[DNT] Meta Ranged Attack on Freeze",
     * "[DNT-UNUSED] Arcane Archery". A check for a bare DNT prefix matches none
     * of them and lets every placeholder into search.
     */
    public function testDeveloperPlaceholdersNeverReachTheCatalog(): void
    {
        $ids = array_column($this->normalize()->gems, 'id');

        self::assertNotContains('Metadata/Items/Gem/SupportGemDntPlaceholder', $ids);
        self::assertNotContains('Metadata/Items/Gem/SupportGemBracketPlaceholder', $ids);
        self::assertCount(3, $ids);
    }

    public function testTheThreeGemKindsAreKept(): void
    {
        $byId = array_column($this->normalize()->gems, null, 'id');

        self::assertSame('active', $byId['Metadata/Items/Gems/SkillGemSyntheticStrike']['kind']);
        self::assertSame('support', $byId['Metadata/Items/Gem/SupportGemSyntheticBoost']['kind']);
        self::assertSame('spirit', $byId['Metadata/Items/Gem/SkillGemSyntheticAura']['kind']);
    }

    public function testThePrimaryAttributeIsTheHeaviestRequirement(): void
    {
        $byId = array_column($this->normalize()->gems, null, 'id');

        self::assertSame('strength', $byId['Metadata/Items/Gems/SkillGemSyntheticStrike']['primary_attribute']);
        self::assertSame('dexterity', $byId['Metadata/Items/Gem/SkillGemSyntheticAura']['primary_attribute']);
    }

    public function testTagsAreCollectedPerGem(): void
    {
        $tags = array_filter($this->normalize()->tags, static fn (array $t): bool => 'Metadata/Items/Gems/SkillGemSyntheticStrike' === $t['gem_id']);

        self::assertSame(['attack', 'melee', 'grants_active_skill'], array_column($tags, 'tag'));
    }

    /**
     * The game's own skill-to-support pairing, and a far better basis for
     * suggestions than inferring compatibility from tags.
     */
    public function testRecommendedSupportsAreKeptOnlyWhereBothGemsExist(): void
    {
        $recommended = $this->normalize()->recommendedSupports;

        self::assertCount(1, $recommended);
        self::assertSame('Metadata/Items/Gem/SupportGemSyntheticBoost', $recommended[0]['support_id']);
    }

    /**
     * Real data lists the same support twice for at least one gem
     * (SkillGemColdSnap in 0.5.5). The pair is the primary key, so a duplicate
     * aborts the whole sync.
     */
    public function testASupportRecommendedTwiceIsStoredOnce(): void
    {
        $pairs = array_map(
            static fn (array $r): string => $r['gem_id'].'|'.$r['support_id'],
            $this->normalize()->recommendedSupports,
        );

        self::assertSame($pairs, array_values(array_unique($pairs)));
    }

    private function normalize(): NormalizedGems
    {
        $json = file_get_contents(__DIR__.'/../fixtures/catalog/skill-gems-shape.json');
        self::assertIsString($json);

        return new GemNormalizer()->normalize($json);
    }
}
