<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\View\CatalogState;
use App\Catalog\View\GemInfo;
use App\Catalog\View\PassiveInfo;
use App\Catalog\View\RequirementInfo;
use App\Catalog\View\UniqueInfo;
use Doctrine\DBAL\Connection;

/**
 * The catalog as stored.
 *
 * Plain DBAL rather than the ORM: every question here is a small read on
 * read-only reference data, and the rules engine asks them in tight loops while
 * checking a build.
 */
final class DoctrineCatalog implements CatalogPort
{
    private ?CatalogState $state = null;
    private bool $stateLoaded = false;

    public function __construct(private readonly Connection $db)
    {
    }

    public function isAvailable(): bool
    {
        return null !== $this->state();
    }

    public function state(): ?CatalogState
    {
        if ($this->stateLoaded) {
            return $this->state;
        }

        $this->stateLoaded = true;

        $row = $this->db->fetchAssociative(
            "SELECT game_version, ran_at FROM catalog_sync WHERE status = 'ok' AND game_version IS NOT NULL ORDER BY id DESC LIMIT 1"
        );

        $version = false === $row ? null : Row::nullableStr($row, 'game_version');
        $ranAt = false === $row ? null : Row::nullableStr($row, 'ran_at');

        if (null === $version || null === $ranAt) {
            return null;
        }

        return $this->state = new CatalogState($version, new \DateTimeImmutable($ranAt));
    }

    public function passive(string $id): ?PassiveInfo
    {
        $row = $this->db->fetchAssociative('SELECT id, name, kind, ascendancy_key, stats FROM catalog_passive WHERE id = ?', [$id]);

        if (false === $row) {
            return null;
        }

        $stats = json_decode(Row::str($row, 'stats', '[]'), true);

        return new PassiveInfo(
            id: Row::str($row, 'id'),
            name: Row::str($row, 'name'),
            kind: Row::str($row, 'kind'),
            ascendancyKey: Row::nullableStr($row, 'ascendancy_key'),
            stats: \is_array($stats) ? array_values(array_filter($stats, is_string(...))) : [],
        );
    }

    public function neighbours(string $id): array
    {
        /** @var list<string> $ids */
        $ids = $this->db->fetchFirstColumn(
            'SELECT to_id FROM catalog_passive_edge WHERE from_id = ? UNION SELECT from_id FROM catalog_passive_edge WHERE to_id = ?',
            [$id, $id],
        );

        return $ids;
    }

    public function gem(string $id): ?GemInfo
    {
        $row = $this->db->fetchAssociative('SELECT id, name, kind, primary_attribute FROM catalog_gem WHERE id = ?', [$id]);

        if (false === $row) {
            return null;
        }

        /** @var list<string> $tags */
        $tags = $this->db->fetchFirstColumn('SELECT tag FROM catalog_gem_tag WHERE gem_id = ?', [$id]);

        return new GemInfo(
            id: Row::str($row, 'id'),
            name: Row::str($row, 'name'),
            kind: Row::str($row, 'kind'),
            tags: $tags,
            primaryAttribute: Row::nullableStr($row, 'primary_attribute'),
        );
    }

    public function supportRequirements(string $supportId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT term, mode, origin, clause FROM catalog_gem_requirement WHERE gem_id = ? ORDER BY id', [$supportId]);

        return array_map(
            static fn (array $row): RequirementInfo => new RequirementInfo(
                term: Row::str($row, 'term'),
                mode: Row::str($row, 'mode'),
                origin: Row::str($row, 'origin'),
                clause: Row::nullableStr($row, 'clause'),
            ),
            $rows,
        );
    }

    public function recommendedSupports(string $gemId): array
    {
        /** @var list<string> $ids */
        $ids = $this->db->fetchFirstColumn('SELECT support_id FROM catalog_gem_recommended_support WHERE gem_id = ? ORDER BY rank', [$gemId]);

        return $ids;
    }

    public function uniquesNamed(string $name): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, name, item_class FROM catalog_unique WHERE name = ? ORDER BY id', [$name]);

        return array_map(
            static fn (array $row): UniqueInfo => new UniqueInfo(Row::str($row, 'id'), Row::str($row, 'name'), Row::str($row, 'item_class')),
            $rows,
        );
    }
}
