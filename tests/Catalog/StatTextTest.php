<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\View\StatText;
use PHPUnit\Framework\TestCase;

final class StatTextTest extends TestCase
{
    public function testAPipedLinkKeepsOnlyWhatThePlayerReads(): void
    {
        self::assertSame(
            '40% reduced Magnitude of Ignite on you',
            StatText::plain('40% reduced [BuffMagnitude|Magnitude] of [Ignite|Ignite] on you'),
        );
    }

    public function testALinkWithoutAPipeKeepsItsOnlyWord(): void
    {
        self::assertSame('50% increased Armour while Ignited', StatText::plain('50% increased [Armour] while [Ignite|Ignited]'));
    }

    public function testTextWithNoMarkupIsUntouched(): void
    {
        self::assertSame('You cannot be interrupted', StatText::plain('You cannot be interrupted'));
    }

    public function testAnUnclosedBracketIsLeftAloneRatherThanEaten(): void
    {
        self::assertSame('broken [Ignite', StatText::plain('broken [Ignite'));
    }

    public function testAnEmotionIdBecomesWords(): void
    {
        self::assertSame('Concentrated Liquid Suffering', StatText::emotion('ConcentratedLiquidSuffering'));
        self::assertSame('Liquid Despair', StatText::emotion('LiquidDespair'));
    }

    public function testAnEmotionIdThatIsAlreadyWordsIsUnchanged(): void
    {
        self::assertSame('Liquid Envy', StatText::emotion('Liquid Envy'));
    }
}
