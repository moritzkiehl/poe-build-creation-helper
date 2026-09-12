<?php

declare(strict_types=1);

namespace App\Catalog\View;

/**
 * Catalog text made readable.
 *
 * Both transforms happen server-side and once, so the canvas tooltip and the
 * server-rendered stats overview share an implementation rather than keeping
 * one each in PHP and JavaScript. The stored text keeps its markup: the link
 * targets may yet be useful, and discarding them at sync time would be harder
 * to undo than formatting at read time.
 */
final class StatText
{
    /**
     * `[Key|Display]` reads as `Display`, `[Key]` as `Key`. Anything that is
     * not a closed pair is left exactly as it is — a malformed line should look
     * wrong rather than quietly lose a word.
     */
    public static function plain(string $stat): string
    {
        return preg_replace_callback(
            '/\[([^\[\]|]+)(?:\|([^\[\]|]+))?\]/',
            static fn (array $m): string => '' !== ($m[2] ?? '') ? $m[2] : $m[1],
            $stat,
        ) ?? $stat;
    }

    /**
     * `ConcentratedLiquidSuffering` reads as `Concentrated Liquid Suffering`.
     *
     * This is a mechanical camel-case split, not a claim about GGG's own
     * wording. If their display names turn out to differ, this becomes a
     * curated map like `inventory_slots.yaml`.
     */
    public static function emotion(string $id): string
    {
        return trim((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $id));
    }
}
