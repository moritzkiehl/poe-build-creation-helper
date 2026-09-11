<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Turns RePoE's base_items file into equippable bases and their tags.
 *
 * The file is a dump of everything the game calls an item: currency, quest
 * items, map fragments, and the gems that already live in their own table. Only
 * released, equippable bases are kept — 2559 of 5496 in 0.5.5.
 *
 * Tags are kept because they are the join to crafting mods: a mod's spawn
 * weights are expressed in exactly this vocabulary, which is how "what can roll
 * on this base" is answered at all.
 */
final class BaseItemNormalizer
{
    /**
     * Item classes that are not equipment. Gems are excluded because they are
     * already modelled as gems, with tags and requirements of their own.
     */
    private const array NOT_EQUIPMENT = [
        'StackableCurrency', 'Currency', 'QuestItem', 'MapFragment', 'HiddenItem',
        'SoulCore', 'Active Skill Gem', 'Support Skill Gem', 'Map', 'DelveSocketableCurrency',
        'MiscMapItem', 'Incubator', 'Leaguestone', 'AtlasUpgradeItem', 'UniqueFragment',
    ];

    public function normalize(string $json): NormalizedBaseItems
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The base item file is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('The base item file is not an object.');
        }

        /** @var array<string, array<string, mixed>> $raw */
        $raw = $data;
        $items = [];
        $tags = [];

        foreach ($raw as $id => $item) {
            $class = $item['item_class'] ?? null;
            $name = $item['name'] ?? null;

            if (!\is_string($class) || !\is_string($name) || '' === $name) {
                continue;
            }

            if ('released' !== ($item['release_state'] ?? null) || \in_array($class, self::NOT_EQUIPMENT, true)) {
                continue;
            }

            $visual = $item['visual_identity'] ?? null;

            $items[] = [
                'id' => (string) $id,
                'name' => $name,
                'item_class' => $class,
                'drop_level' => $this->intOr($item, 'drop_level', 0),
                'inventory_width' => $this->intOr($item, 'inventory_width', 1),
                'inventory_height' => $this->intOr($item, 'inventory_height', 1),
                'icon' => \is_array($visual) && \is_string($visual['dds_file'] ?? null) ? $visual['dds_file'] : null,
            ];

            // A base can list the same tag twice (ExpeditionLogbook in 0.5.5),
            // and the pair is a primary key.
            $seen = [];
            foreach ((array) ($item['tags'] ?? []) as $tag) {
                if (\is_string($tag) && !isset($seen[$tag])) {
                    $seen[$tag] = true;
                    $tags[] = ['base_item_id' => (string) $id, 'tag' => $tag];
                }
            }
        }

        return new NormalizedBaseItems(items: $items, tags: $tags);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intOr(array $row, string $key, int $default): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : $default;
    }
}
