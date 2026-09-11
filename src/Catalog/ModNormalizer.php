<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Turns RePoE's mods file into the craftable item modifiers, with the base tags
 * each one can roll on.
 *
 * Only `domain: item` prefixes and suffixes are kept — 2586 of 16784 in 0.5.5.
 * Everything else is either not about equipment (monster, area, flask, chest)
 * or is a unique-generation mod, and those are deliberately left out: 10447 of
 * them exist with readable text, but nothing in any published file says which
 * unique item they belong to. Storing them would produce a searchable list that
 * can never be attached to an item.
 *
 * A spawn weight of zero means the mod cannot roll on that tag, so it is not a
 * link and is not stored.
 *
 * None of this can ever be checked against a player's build: the `.build` format
 * carries no rare items and no modifiers. It exists for browsing, for crafting
 * guidance, and for curated rules to point at.
 */
final class ModNormalizer
{
    public function normalize(string $json): NormalizedMods
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The mod file is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('The mod file is not an object.');
        }

        /** @var array<string, array<string, mixed>> $raw */
        $raw = $data;
        $mods = [];
        $spawnTags = [];

        foreach ($raw as $id => $mod) {
            $type = $mod['generation_type'] ?? null;

            if ('item' !== ($mod['domain'] ?? null) || !\in_array($type, ['prefix', 'suffix'], true)) {
                continue;
            }

            $text = $mod['text'] ?? null;
            if (!\is_string($text) || '' === $text) {
                continue;
            }

            $mods[] = [
                'id' => (string) $id,
                'name' => \is_string($mod['name'] ?? null) ? $mod['name'] : '',
                'text' => $text,
                'generation_type' => $type,
                'required_level' => $this->intOr($mod, 'required_level', 0),
            ];

            $seen = [];
            foreach ((array) ($mod['spawn_weights'] ?? []) as $weight) {
                if (!\is_array($weight)) {
                    continue;
                }

                $tag = $weight['tag'] ?? null;
                if (!\is_string($tag) || isset($seen[$tag])) {
                    continue;
                }

                $value = is_numeric($weight['weight'] ?? null) ? (int) $weight['weight'] : 0;

                // A weight of zero means the mod cannot roll on that tag, so it
                // is not a link. First entry wins for repeats: the pair is a
                // primary key.
                if ($value > 0) {
                    $seen[$tag] = true;
                    $spawnTags[] = ['mod_id' => (string) $id, 'tag' => $tag, 'weight' => $value];
                }
            }
        }

        return new NormalizedMods(mods: $mods, spawnTags: $spawnTags);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intOr(array $row, string $key, int $default): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : $default;
    }
}
