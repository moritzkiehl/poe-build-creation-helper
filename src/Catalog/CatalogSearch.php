<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\View\StatText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads the catalog for the browse view.
 *
 * Separate from `CatalogPort` on purpose: the port is the narrow set of
 * questions the rules engine asks, while this is presentation — paging,
 * searching and the counts beside the filters. Mixing them would grow the port
 * with things no rule needs.
 *
 * @phpstan-type ResultRow array{id: string, name: string, kind: string, detail: string, tags: string, ambiguous: bool}
 */
final class CatalogSearch
{
    private const int LIMIT = 100;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<ResultRow>
     */
    public function search(?string $query, string $kind): array
    {
        $rows = 'unique' === $kind ? $this->uniques($query) : $this->gems($query, $kind);

        return \array_slice($rows, 0, self::LIMIT);
    }

    /**
     * Every keystone, for the jewel keystone picker. All 33 cover at least one
     * node (measured 2026-09-13), so none is filtered out.
     *
     * @return list<array{id: string, name: string}>
     */
    public function keystones(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => Row::str($row, 'id'), 'name' => Row::str($row, 'name')],
            $this->db->fetchAllAssociative("SELECT id, name FROM catalog_passive WHERE kind = 'keystone' ORDER BY name"),
        );
    }

    /**
     * Passives for the node list beside the tree. Ids are matched as well as
     * names: a finding names a node by id, and the list is where that id is
     * looked up.
     *
     * @return list<array{id: string, name: string, kind: string, stats: list<string>}>
     */
    public function passives(string $query): array
    {
        if ('' === trim($query)) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, kind, stats FROM catalog_passive WHERE name LIKE ? OR id LIKE ? ORDER BY name LIMIT 50',
            ['%'.$query.'%', '%'.$query.'%'],
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'stats' => array_map(StatText::plain(...), Row::jsonStrings($row, 'stats')),
            ];
        }

        return $results;
    }

    /**
     * Passives that can carry an Instilled Modifier — the 875 that have a
     * Distilled Emotion recipe. Searching the other four thousand would offer
     * the player nodes the mechanic cannot reach.
     *
     * @return list<array{id: string, name: string, kind: string, stats: list<string>, recipe: list<string>}>
     */
    public function instillablePassives(string $query): array
    {
        if ('' === trim($query)) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, kind, stats, recipe FROM catalog_passive
             WHERE JSON_LENGTH(recipe) > 0 AND (name LIKE ? OR id LIKE ?)
             ORDER BY name LIMIT 50',
            ['%'.$query.'%', '%'.$query.'%'],
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'stats' => array_map(StatText::plain(...), Row::jsonStrings($row, 'stats')),
                'recipe' => array_map(StatText::emotion(...), Row::jsonStrings($row, 'recipe')),
            ];
        }

        return $results;
    }

    /**
     * Detail for a known set of ids — what the stats overview and the instilled
     * list both need. Ids come from a build document, so the set is small.
     *
     * @param list<string> $ids
     *
     * @return list<array{id: string, name: string, kind: string, stats: list<string>, recipe: list<string>}>
     */
    public function passivesByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, kind, stats, recipe FROM catalog_passive WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );

        $found = [];

        foreach ($rows as $row) {
            $found[] = [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'stats' => array_map(StatText::plain(...), Row::jsonStrings($row, 'stats')),
                'recipe' => array_map(StatText::emotion(...), Row::jsonStrings($row, 'recipe')),
            ];
        }

        return $found;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->db->fetchAllAssociative('SELECT kind, COUNT(*) AS n FROM catalog_gem GROUP BY kind') as $row) {
            $counts[Row::str($row, 'kind')] = Row::int($row, 'n');
        }

        $uniques = $this->db->fetchOne('SELECT COUNT(*) FROM catalog_unique');
        $counts['unique'] = is_numeric($uniques) ? (int) $uniques : 0;
        $counts['all'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return list<ResultRow>
     */
    private function gems(?string $query, string $kind): array
    {
        $sql = 'SELECT g.id, g.name, g.kind, g.primary_attribute, GROUP_CONCAT(t.tag ORDER BY t.tag SEPARATOR ", ") AS tags
                FROM catalog_gem g LEFT JOIN catalog_gem_tag t ON t.gem_id = g.id';
        $where = [];
        $params = [];

        if ('all' !== $kind) {
            $where[] = 'g.kind = ?';
            $params[] = $kind;
        }

        if (null !== $query && '' !== $query) {
            $where[] = '(g.name LIKE ? OR g.id LIKE ?)';
            $params[] = '%'.$query.'%';
            $params[] = '%'.$query.'%';
        }

        if ([] !== $where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' GROUP BY g.id ORDER BY g.name LIMIT '.self::LIMIT;

        return array_map(
            static fn (array $row): array => [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => Row::str($row, 'kind'),
                'detail' => Row::str($row, 'primary_attribute'),
                'tags' => Row::str($row, 'tags'),
                'ambiguous' => false,
            ],
            $this->db->fetchAllAssociative($sql, $params),
        );
    }

    /**
     * A unique whose name belongs to more than one item is flagged, because
     * `.build` identifies uniques by name and cannot tell them apart.
     *
     * @return list<ResultRow>
     */
    private function uniques(?string $query): array
    {
        $sql = 'SELECT u.id, u.name, u.item_class, (SELECT COUNT(*) FROM catalog_unique s WHERE s.name = u.name) AS shared FROM catalog_unique u';
        $params = [];

        if (null !== $query && '' !== $query) {
            $sql .= ' WHERE u.name LIKE ?';
            $params[] = '%'.$query.'%';
        }

        $sql .= ' ORDER BY u.name LIMIT '.self::LIMIT;

        return array_map(
            static fn (array $row): array => [
                'id' => Row::str($row, 'id'),
                'name' => Row::str($row, 'name'),
                'kind' => 'unique',
                'detail' => Row::str($row, 'item_class'),
                'tags' => '',
                'ambiguous' => Row::int($row, 'shared') > 1,
            ],
            $this->db->fetchAllAssociative($sql, $params),
        );
    }
}
