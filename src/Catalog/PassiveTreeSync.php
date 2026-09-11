<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\CatalogSync;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fetches the official skill tree export and replaces the stored tree with it.
 *
 * Two properties matter more than speed. A failed or unparsable upstream must
 * leave the existing catalog untouched — the app has to keep working on
 * yesterday's data rather than on none (measure 8). And a rerun must replace
 * rather than accumulate, so the table always mirrors exactly one export.
 *
 * Rows are written through DBAL in batches rather than as entities: this is
 * five thousand nodes and six thousand edges of read-only reference data, and
 * hydrating them through the ORM would buy nothing.
 */
final class PassiveTreeSync
{
    private const int BATCH = 500;

    public function __construct(
        private readonly SourceFetcher $fetcher,
        private readonly PassiveTreeNormalizer $normalizer,
        private readonly Connection $db,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.default_game_version%')]
        private readonly string $gameVersion,
        #[Autowire('%app.catalog.passive_tree_url%')]
        private readonly string $url,
    ) {
    }

    public function run(): SyncResult
    {
        $fetch = $this->fetcher->fetch('passive_tree', $this->url, $this->lastKnownRevision());

        if (!$fetch->ok) {
            return $this->record(new SyncResult(false, 'failed', 0, $fetch->error), $fetch->revision);
        }

        // Upstream says nothing moved. Rebuilding five thousand rows to arrive
        // at the same five thousand rows helps nobody.
        if (!$fetch->changed && $this->alreadyPopulated()) {
            return $this->record(new SyncResult(true, 'unchanged', $this->storedCount()), $fetch->revision);
        }

        try {
            $tree = $this->normalizer->normalize($fetch->body);
        } catch (\RuntimeException $e) {
            return $this->record(new SyncResult(false, 'failed', 0, $e->getMessage()), $fetch->revision);
        }

        $this->replace($tree);

        return $this->record(new SyncResult(true, 'ok', \count($tree->nodes)), $fetch->revision);
    }

    private function replace(NormalizedTree $tree): void
    {
        $this->db->transactional(static function (Connection $db) use ($tree): void {
            $db->executeStatement('DELETE FROM catalog_passive_edge');
            $db->executeStatement('DELETE FROM catalog_passive');

            foreach (array_chunk($tree->nodes, self::BATCH) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $node) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $node['id'], $node['name'], $node['kind'], $node['ascendancy_key'], $node['pos_x'], $node['pos_y'], json_encode($node['stats'], \JSON_THROW_ON_ERROR));
                }
                $db->executeStatement('INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats) VALUES '.implode(',', $values), $params);
            }

            foreach (array_chunk($tree->edges, self::BATCH) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as [$from, $to]) {
                    $values[] = '(?, ?)';
                    array_push($params, $from, $to);
                }
                $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES '.implode(',', $values), $params);
            }
        });
    }

    private function lastKnownRevision(): ?string
    {
        $value = $this->db->fetchOne(
            "SELECT upstream_revision FROM catalog_sync WHERE source = 'passive_tree' AND status IN ('ok', 'unchanged') AND upstream_revision IS NOT NULL ORDER BY id DESC LIMIT 1"
        );

        return \is_string($value) ? $value : null;
    }

    private function alreadyPopulated(): bool
    {
        return $this->storedCount() > 0;
    }

    private function storedCount(): int
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM catalog_passive');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function record(SyncResult $result, ?string $upstreamRevision): SyncResult
    {
        $this->entityManager->persist(new CatalogSync(
            source: 'passive_tree',
            status: $result->status,
            count: $result->count,
            upstreamRevision: $upstreamRevision,
            error: $result->error,
            gameVersion: $this->gameVersion,
        ));
        $this->entityManager->flush();

        return $result;
    }
}
