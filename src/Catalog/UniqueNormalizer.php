<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Turns RePoE's uniques file into rows.
 *
 * Two things this data does not contain, both verified against 0.5.5: a unique
 * has no modifiers, and nothing links the 10447 unique-generation mods to the
 * 441 unique items. So the catalog can say what a unique is called, what class
 * it is and how big it is — and nothing about what it does. Anything the
 * interface claims about a unique's effect has to come from
 * `config/knowledge/interactions.yaml`.
 *
 * Rows keep RePoE's own key rather than the name, because names are not unique:
 * Grand Spectrum, Grip of Kulemak and Guiding Palm cover eleven distinct items
 * between them.
 */
final class UniqueNormalizer
{
    /**
     * @return list<array{id: string, name: string, item_class: string, inventory_width: int, inventory_height: int, icon: string|null}>
     */
    public function normalize(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The uniques file is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('The uniques file is not an object.');
        }

        /** @var array<string, array<string, mixed>> $raw */
        $raw = $data;
        $out = [];

        foreach ($raw as $key => $unique) {
            $name = $unique['name'] ?? null;
            $class = $unique['item_class'] ?? null;

            if (!\is_string($name) || '' === $name || !\is_string($class)) {
                continue;
            }

            $visual = $unique['visual_identity'] ?? null;

            $out[] = [
                'id' => (string) $key,
                'name' => $name,
                'item_class' => $class,
                'inventory_width' => $this->intOr($unique, 'inventory_width', 1),
                'inventory_height' => $this->intOr($unique, 'inventory_height', 1),
                'icon' => \is_array($visual) && \is_string($visual['dds_file'] ?? null) ? $visual['dds_file'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intOr(array $row, string $key, int $default): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : $default;
    }
}
