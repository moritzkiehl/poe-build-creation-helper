<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Turns RePoE's skill_gems file into gems, their tags, and the game's own
 * skill-to-support recommendations.
 *
 * Identifiers are stored exactly as they arrive. RePoE writes three prefixes —
 * Metadata/Items/Gem, Metadata/Items/Gems and a lowercase Metadata/items/Gems —
 * and the first two both appear in real game-exported build files, so this is
 * the game's own inconsistency rather than an upstream mistake. Normalising it
 * would produce exports Path of Exile 2 cannot resolve.
 *
 * Entries whose display name begins with DNT are developer placeholders and are
 * dropped: 77 of them ship in 0.5.5, marked as "[DNT] ..." or "[DNT-UNUSED] ...".
 * They would otherwise turn up in search looking like real gems.
 */
final class GemNormalizer
{
    public function normalize(string $json): NormalizedGems
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The gem file is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('The gem file is not an object.');
        }

        /** @var array<string, array<string, mixed>> $raw */
        $raw = $data;

        $gems = [];
        $tags = [];
        $supportTexts = [];
        $kept = [];

        foreach ($raw as $id => $gem) {
            $name = $this->name($gem);
            $kind = $gem['gem_type'] ?? null;

            if ('' === $name || $this->isPlaceholder($name) || !\is_string($kind)) {
                continue;
            }

            $kept[$id] = true;
            $gems[] = [
                'id' => $id,
                'name' => $name,
                'kind' => $kind,
                'primary_attribute' => $this->primaryAttribute($gem),
                'icon' => \is_string($gem['icon_dds_file'] ?? null) ? $gem['icon_dds_file'] : null,
            ];

            foreach ((array) ($gem['tags'] ?? []) as $tag) {
                if (\is_string($tag)) {
                    $tags[] = ['gem_id' => $id, 'tag' => $tag];
                }
            }

            if (\is_string($gem['support_text'] ?? null) && '' !== $gem['support_text']) {
                $supportTexts[] = ['id' => $id, 'text' => $gem['support_text']];
            }
        }

        return new NormalizedGems(
            gems: $gems,
            tags: $tags,
            recommendedSupports: $this->recommendations($raw, $kept),
            supportTexts: $supportTexts,
        );
    }

    /**
     * @param array<string, mixed> $gem
     */
    private function name(array $gem): string
    {
        $base = $gem['base_item'] ?? null;
        if (!\is_array($base)) {
            return '';
        }

        return \is_string($base['display_name'] ?? null) ? $base['display_name'] : '';
    }

    /**
     * The marker is bracketed in the data: "[DNT] Meta Ranged Attack on Freeze",
     * "[DNT-UNUSED] Arcane Archery". 77 entries carry it in 0.5.5.
     */
    private function isPlaceholder(string $name): bool
    {
        return 1 === preg_match('/^\[?DNT\b/i', $name);
    }

    /**
     * RePoE gives an attribute split rather than a single attribute. The heaviest
     * weight is the one the interface wants to show and the catalog wants to
     * filter on.
     *
     * @param array<string, mixed> $gem
     */
    private function primaryAttribute(array $gem): ?string
    {
        $weights = $gem['requirement_weights'] ?? null;
        if (!\is_array($weights) || [] === $weights) {
            return null;
        }

        $best = null;
        $bestWeight = 0;
        foreach ($weights as $attribute => $weight) {
            if (\is_string($attribute) && is_numeric($weight) && $weight > $bestWeight) {
                $best = $attribute;
                $bestWeight = (int) $weight;
            }
        }

        return $best;
    }

    /**
     * A recommendation pointing at a gem we dropped is not a recommendation.
     *
     * @param array<string, array<string, mixed>> $raw
     * @param array<string, true>                 $kept
     *
     * @return list<array{gem_id: string, support_id: string, rank: int}>
     */
    private function recommendations(array $raw, array $kept): array
    {
        $out = [];

        foreach ($raw as $id => $gem) {
            if (!isset($kept[$id])) {
                continue;
            }

            $rank = 0;
            $seen = [];
            foreach ((array) ($gem['recommended_supports'] ?? []) as $support) {
                // At least one gem lists the same support twice (SkillGemColdSnap
                // in 0.5.5). The pair is a primary key, so a duplicate would
                // abort the entire sync.
                if (\is_string($support) && isset($kept[$support]) && !isset($seen[$support])) {
                    $seen[$support] = true;
                    $out[] = ['gem_id' => $id, 'support_id' => $support, 'rank' => $rank++];
                }
            }
        }

        return $out;
    }
}
