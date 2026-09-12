<?php

declare(strict_types=1);

namespace App\Build;

/**
 * What an allocated tree adds up to.
 *
 * Nodes group by id family — the id without its number — because the tree
 * names them that way: `criticals7` and `criticals38` are two of the ninety-nine
 * `criticals` nodes.
 *
 * Lines are summed by a rule holding no game knowledge at all. Replace every
 * number in a line with a placeholder; lines sharing that shape, and carrying
 * exactly one number, are the same statement at different magnitudes, so their
 * numbers add. Everything else is listed verbatim with a count.
 *
 * The rule can therefore never be wrong about a mechanic — it can only decline
 * to sum, and a declined line is shown in full. That visible failure is why
 * this needs no accuracy measurement: nothing it cannot read disappears.
 *
 * The placeholder is a NUL byte, not `%s`: stat text is data, not a format
 * string, and most tree stats are percentages, so a `%s%` template fed back
 * through `sprintf` misreads the literal `%` as a conversion specifier. A
 * NUL byte cannot occur in catalog text, and the total is spliced back in
 * with `str_replace`, which never interprets the template it writes into.
 */
final class StatSummary
{
    private const string NUMBER = '[+-]?\d+(?:\.\d+)?';
    private const string PLACEHOLDER = "\x00";

    public static function family(string $passiveId): string
    {
        return (string) preg_replace('/\d+_?$/', '', $passiveId);
    }

    /**
     * @param array<string, list<string>> $statsById allocated passive id => its plain stat lines
     *
     * @return list<array{key: string, nodes: int, summed: list<string>, listed: list<array{text: string, count: int}>}>
     */
    public static function of(array $statsById): array
    {
        /** @var array<string, array{nodes: int, lines: list<string>}> $families */
        $families = [];

        foreach ($statsById as $id => $stats) {
            $key = self::family($id);
            $families[$key] ??= ['nodes' => 0, 'lines' => []];
            ++$families[$key]['nodes'];

            foreach ($stats as $line) {
                $families[$key]['lines'][] = $line;
            }
        }

        ksort($families);

        $summary = [];

        foreach ($families as $key => $family) {
            ['summed' => $summed, 'listed' => $listed] = self::fold($family['lines']);
            $summary[] = ['key' => $key, 'nodes' => $family['nodes'], 'summed' => $summed, 'listed' => $listed];
        }

        return $summary;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{summed: list<string>, listed: list<array{text: string, count: int}>}
     */
    private static function fold(array $lines): array
    {
        /** @var array<string, array{total: float, template: string, decimals: int, signed: bool}> $summable */
        $summable = [];
        /** @var array<string, int> $plain */
        $plain = [];

        foreach ($lines as $line) {
            $numbers = [];
            preg_match_all('/'.self::NUMBER.'/', $line, $numbers);

            if (1 !== \count($numbers[0])) {
                $plain[$line] = ($plain[$line] ?? 0) + 1;
                continue;
            }

            $value = $numbers[0][0];
            $shape = (string) preg_replace('/'.self::NUMBER.'/', self::PLACEHOLDER, $line);

            $summable[$shape] ??= [
                'total' => 0.0,
                'template' => $shape,
                'decimals' => 0,
                'signed' => str_starts_with($value, '+'),
            ];
            $summable[$shape]['total'] += (float) $value;
            $dot = strpos($value, '.');
            $decimals = false === $dot ? 0 : \strlen($value) - $dot - 1;
            $summable[$shape]['decimals'] = max($summable[$shape]['decimals'], $decimals);
        }

        $summed = [];

        foreach ($summable as $entry) {
            $number = number_format($entry['total'], $entry['decimals'], '.', '');

            if ($entry['signed'] && !str_starts_with($number, '-')) {
                $number = '+'.$number;
            }

            $summed[] = str_replace(self::PLACEHOLDER, $number, $entry['template']);
        }

        $listed = [];

        foreach ($plain as $text => $count) {
            $listed[] = ['text' => (string) $text, 'count' => $count];
        }

        return ['summed' => $summed, 'listed' => $listed];
    }
}
