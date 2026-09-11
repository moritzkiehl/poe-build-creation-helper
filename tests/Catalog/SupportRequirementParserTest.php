<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\SupportRequirementParser;
use PHPUnit\Framework\TestCase;

/**
 * The sentences below follow the patterns measured across 630 support gems in
 * 0.5.5. Bracket markup names canonical game terms — [Curse],
 * [CriticalDamageBonus|Critical Damage Bonus] — and those terms, not the 59-value
 * gem tag list, are what a requirement can be expressed in: only 62% of the
 * clauses contain a word the tag vocabulary knows.
 */
final class SupportRequirementParserTest extends TestCase
{
    public function testThePositiveClauseBecomesARequirement(): void
    {
        $parsed = $this->parse('Supports [Melee] Attack Skills you use yourself.');

        self::assertSame(['Melee'], $parsed->requires);
        self::assertSame([], $parsed->excludes);
    }

    public function testACannotSupportClauseBecomesAnExclusion(): void
    {
        $parsed = $this->parse('Supports [Attack] Skills. Cannot Support [Channelling] Skills.');

        self::assertSame(['Attack'], $parsed->requires);
        self::assertSame(['Channelling'], $parsed->excludes);
    }

    public function testTheCanonicalTermIsTakenFromTheLeftOfAPipe(): void
    {
        $parsed = $this->parse('Supports [CriticalDamageBonus|Critical Damage Bonus] Skills.');

        self::assertSame(['CriticalDamageBonus'], $parsed->requires);
    }

    /**
     * "and does not modify Skills used by Minions" describes behaviour, not a
     * restriction. A parser that reads it as one would exclude minion skills
     * from supports that work on them perfectly well.
     */
    public function testABehaviourNoteIsNotReadAsARestriction(): void
    {
        $parsed = $this->parse('Cannot Support [Channelling] Skills and does not modify Skills used by [Minion|Minions].');

        self::assertSame(['Channelling'], $parsed->excludes);
        self::assertNotContains('Minion', $parsed->excludes);
    }

    /**
     * Roughly two clauses in five say something the term vocabulary cannot
     * express. Those must be reported rather than silently turned into an empty
     * requirement that passes every build.
     */
    public function testAClauseWithNoCanonicalTermIsReportedAsUnparsed(): void
    {
        $parsed = $this->parse('Supports skills that have cooldowns.');

        self::assertSame([], $parsed->requires);
        self::assertFalse($parsed->complete);
        self::assertContains('skills that have cooldowns', $parsed->unparsed);
    }

    public function testAFullyUnderstoodTextIsMarkedComplete(): void
    {
        self::assertTrue($this->parse('Supports [Melee] Attack Skills.')->complete);
    }

    public function testTextWithoutAnyClauseYieldsNothingAndSaysSo(): void
    {
        $parsed = $this->parse('This gem does something unusual.');

        self::assertSame([], $parsed->requires);
        self::assertFalse($parsed->complete);
    }

    private function parse(string $text): \App\Catalog\ParsedRequirements
    {
        return new SupportRequirementParser()->parse($text);
    }
}
