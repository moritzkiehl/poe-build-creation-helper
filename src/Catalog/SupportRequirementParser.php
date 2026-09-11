<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Reads support compatibility out of the prose RePoE ships as `support_text`.
 *
 * There is no machine-readable field for this anywhere: support gems carry tags
 * describing themselves, never what they may be socketed into. What they do
 * carry is bracket markup naming canonical game terms — [Curse],
 * [CriticalDamageBonus|Critical Damage Bonus] — and those terms are the
 * vocabulary a requirement can be stated in.
 *
 * Measured over the 630 support gems of 0.5.5: 97% open with a "Supports X"
 * clause, and only 32 carry an explicit "Cannot Support" clause, so the positive
 * clause is the real constraint nearly everywhere. Roughly two clauses in five
 * say something no term can express ("skills you use yourself", "skills that
 * have cooldowns"). Those are reported through `unparsed` and leave `complete`
 * false, because a requirement silently parsed as empty would pass every build
 * and look like a check that ran.
 *
 * This is why findings derived from this parser are warnings, never errors, and
 * why `support_requirements.yaml` exists to override it.
 */
final class SupportRequirementParser
{
    /**
     * A restriction clause often continues into a description of behaviour.
     * "Cannot Support Channelled Skills and does not modify Skills used by
     * Minions" is one restriction plus one aside; reading the aside as a second
     * restriction would rule out supports that work on minions perfectly well.
     */
    private const string ASIDE = '/\s*(?:,\s*)?\b(?:and\s+)?(?:does not modify|causing|allowing|making|giving|but)\b.*$/i';

    public function parse(string $supportText): ParsedRequirements
    {
        $text = $this->stripMarkupKeepingTerms($supportText);

        $requires = [];
        $excludes = [];
        $unparsed = [];
        $clauses = 0;

        foreach ($this->clauses($text, '/(?:^|\.\s*)Supports\s+(.+?)(?:\.|$)/i') as $clause) {
            ++$clauses;
            $terms = $this->terms($clause);
            if ([] === $terms) {
                $unparsed[] = $this->plain($clause);
                continue;
            }
            $requires = [...$requires, ...$terms];
        }

        foreach ($this->clauses($text, '/Cannot [Ss]upport\s+(.+?)(?:\.|$)/') as $clause) {
            ++$clauses;
            $terms = $this->terms($clause);
            if ([] === $terms) {
                $unparsed[] = $this->plain($clause);
                continue;
            }
            $excludes = [...$excludes, ...$terms];
        }

        return new ParsedRequirements(
            requires: array_values(array_unique($requires)),
            excludes: array_values(array_unique($excludes)),
            unparsed: $unparsed,
            complete: $clauses > 0 && [] === $unparsed,
        );
    }

    /**
     * @return list<string>
     */
    private function clauses(string $text, string $pattern): array
    {
        if (!preg_match_all($pattern, $text, $matches)) {
            return [];
        }

        return array_map(static fn (string $clause): string => (string) preg_replace(self::ASIDE, '', $clause), $matches[1]);
    }

    /**
     * Keeps the bracket markers so a term can be told apart from ordinary prose,
     * and reduces [Id|Display] to Id — the canonical side.
     */
    private function stripMarkupKeepingTerms(string $text): string
    {
        return (string) preg_replace('/\[([^\]|]+)\|[^\]]*\]/', '[$1]', $text);
    }

    /**
     * @return list<string>
     */
    private function terms(string $clause): array
    {
        preg_match_all('/\[([^\]]+)\]/', $clause, $matches);

        return $matches[1];
    }

    private function plain(string $clause): string
    {
        return trim((string) preg_replace('/\[([^\]]+)\]/', '$1', $clause));
    }
}
