<?php

declare(strict_types=1);

namespace App\Build\Edit;

use App\Interchange\BuildDocument;

/**
 * Which interval editor a build opens in.
 *
 * Derived from the document every time, never stored. A stored mode could
 * disagree with the document it claims to describe; a derived one cannot. The
 * cost is one harmless quirk — a build edited per-passive into uniform values
 * reopens flattened — and the data is identical either way.
 *
 * A game-exported file may carry genuinely different intervals per node, and
 * flattening those on import would destroy what the round-trip guarantee exists
 * to protect. So the file's own shape chooses the mode.
 */
final class IntervalMode
{
    private const int MIN_LEVEL = 0;
    private const int MAX_LEVEL = 100;

    public static function passivesAreUniform(BuildDocument $document): bool
    {
        $seen = null;

        foreach ($document->passives as $passive) {
            $interval = self::interval($passive);

            if (null === $seen) {
                $seen = $interval;
                continue;
            }

            if ($interval !== $seen) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{int, int} lowest from, highest to
     */
    public static function passiveSpan(BuildDocument $document): array
    {
        $from = null;
        $to = null;

        foreach ($document->passives as $passive) {
            [$start, $end] = self::interval($passive);
            $from = null === $from ? $start : min($from, $start);
            $to = null === $to ? $end : max($to, $end);
        }

        return [$from ?? 1, $to ?? self::MAX_LEVEL];
    }

    public static function supportsFollowTheirSkills(BuildDocument $document): bool
    {
        foreach ($document->skills as $skill) {
            $skillInterval = self::interval($skill);

            foreach ((array) ($skill['support_skills'] ?? []) as $support) {
                if (!\is_array($support)) {
                    continue;
                }

                /** @var array<string, mixed> $support */
                if (self::interval($support) !== $skillInterval) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{int, int}
     */
    private static function interval(array $entry): array
    {
        $interval = $entry['level_interval'] ?? null;

        if (!\is_array($interval) || 2 !== \count($interval)) {
            return [1, self::MAX_LEVEL];
        }

        $from = is_numeric($interval[0] ?? null) ? (int) $interval[0] : 1;
        $to = is_numeric($interval[1] ?? null) ? (int) $interval[1] : self::MAX_LEVEL;

        return [max(self::MIN_LEVEL, $from), min(self::MAX_LEVEL, $to)];
    }
}
