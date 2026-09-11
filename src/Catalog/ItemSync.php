<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\CatalogSync;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fetches uniques, equippable base items and craftable mods.
 *
 * The three travel together because they are one subject: what a player can
 * wear, and what can be rolled onto it. Base item tags are the join to mod spawn
 * weights, so syncing one without the other leaves a table that answers nothing.
 *
 * Same guarantees as the other syncs: a failed source leaves the stored catalog
 * untouched, and a rerun replaces rather than accumulates.
 */
final class ItemSync
{
    public function __construct(
        private readonly SourceFetcher $fetcher,
        private readonly UniqueNormalizer $uniques,
        private readonly BaseItemNormalizer $baseItems,
        private readonly ModNormalizer $mods,
        private readonly BulkWriter $writer,
        private readonly Connection $db,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.catalog.uniques_url%')]
        private readonly string $uniquesUrl,
        #[Autowire('%app.catalog.base_items_url%')]
        private readonly string $baseItemsUrl,
        #[Autowire('%app.catalog.mods_url%')]
        private readonly string $modsUrl,
        #[Autowire('%app.default_game_version%')]
        private readonly string $gameVersion,
    ) {
    }

    public function run(): SyncResult
    {
        $sources = [
            'uniques' => $this->uniquesUrl,
            'base_items' => $this->baseItemsUrl,
            'mods' => $this->modsUrl,
        ];

        $bodies = [];
        foreach ($sources as $source => $url) {
            $fetch = $this->fetcher->fetch($source, $url, $this->lastKnownRevision($source));

            if (!$fetch->ok) {
                return $this->record(new SyncResult(false, 'failed', 0, \sprintf('%s: %s', $source, $fetch->error ?? 'failed')), null);
            }

            $bodies[$source] = $fetch->body;
            $revisions[$source] = $fetch->revision;
        }

        try {
            $uniques = $this->uniques->normalize($bodies['uniques']);
            $bases = $this->baseItems->normalize($bodies['base_items']);
            $mods = $this->mods->normalize($bodies['mods']);
        } catch (\RuntimeException $e) {
            return $this->record(new SyncResult(false, 'failed', 0, $e->getMessage()), null);
        }

        $this->db->transactional(function (Connection $db) use ($uniques, $bases, $mods): void {
            foreach (['catalog_mod_spawn_tag', 'catalog_mod', 'catalog_base_item_tag', 'catalog_base_item', 'catalog_unique'] as $table) {
                $db->executeStatement('DELETE FROM '.$table);
            }

            $this->writer->insert($db, 'catalog_unique', ['id', 'name', 'item_class', 'inventory_width', 'inventory_height', 'icon'], $uniques);
            $this->writer->insert($db, 'catalog_base_item', ['id', 'name', 'item_class', 'drop_level', 'inventory_width', 'inventory_height', 'icon'], $bases->items);
            $this->writer->insert($db, 'catalog_base_item_tag', ['base_item_id', 'tag'], $bases->tags);
            $this->writer->insert($db, 'catalog_mod', ['id', 'name', 'text', 'generation_type', 'required_level'], $mods->mods);
            $this->writer->insert($db, 'catalog_mod_spawn_tag', ['mod_id', 'tag', 'weight'], $mods->spawnTags);
        });

        $counted = \count($uniques) + \count($bases->items) + \count($mods->mods);

        return $this->record(new SyncResult(true, 'ok', $counted), $revisions['mods'] ?? null);
    }

    private function lastKnownRevision(string $source): ?string
    {
        $value = $this->db->fetchOne(
            "SELECT upstream_revision FROM catalog_sync WHERE source = ? AND status IN ('ok', 'unchanged') AND upstream_revision IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$source],
        );

        return \is_string($value) ? $value : null;
    }

    private function record(SyncResult $result, ?string $upstreamRevision): SyncResult
    {
        $this->entityManager->persist(new CatalogSync(
            source: 'items',
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
