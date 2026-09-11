<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\CatalogSync;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fetches the gem data and rebuilds the gem side of the catalog, including the
 * requirements read out of each support gem's prose.
 *
 * Same two guarantees as the tree sync: a broken or unchanged upstream leaves
 * the stored catalog alone, and a rerun replaces rather than accumulates.
 */
final class GemSync
{
    private const int BATCH = 500;

    /** @var list<array{clause: string, gem: string}> */
    private array $unparsed = [];

    public function __construct(
        private readonly SourceFetcher $fetcher,
        private readonly GemNormalizer $normalizer,
        private readonly SupportRequirementParser $parser,
        private readonly Connection $db,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.catalog.gems_url%')]
        private readonly string $url,
    ) {
    }

    public function run(): SyncResult
    {
        $fetch = $this->fetcher->fetch('skill_gems', $this->url, $this->lastKnownRevision());

        if (!$fetch->ok) {
            return $this->record(new SyncResult(false, 'failed', 0, $fetch->error), $fetch->revision);
        }

        if (!$fetch->changed && $this->storedCount() > 0) {
            return $this->record(new SyncResult(true, 'unchanged', $this->storedCount()), $fetch->revision);
        }

        try {
            $gems = $this->normalizer->normalize($fetch->body);
        } catch (\RuntimeException $e) {
            return $this->record(new SyncResult(false, 'failed', 0, $e->getMessage()), $fetch->revision);
        }

        $this->replace($gems);

        return $this->record(new SyncResult(true, 'ok', \count($gems->gems)), $fetch->revision);
    }

    /**
     * Every clause the parser could not turn into a term, from the last run.
     * The design document requires this to be reportable: a requirement quietly
     * parsed as empty would pass every build and look like a check that ran.
     *
     * @return list<array{clause: string, gem: string}>
     */
    public function unparsedClauses(): array
    {
        return $this->unparsed;
    }

    private function replace(NormalizedGems $gems): void
    {
        $requirements = $this->requirements($gems);

        $this->db->transactional(function (Connection $db) use ($gems, $requirements): void {
            foreach (['catalog_gem_requirement', 'catalog_gem_recommended_support', 'catalog_gem_tag', 'catalog_gem'] as $table) {
                $db->executeStatement('DELETE FROM '.$table);
            }

            $this->insert($db, 'catalog_gem', ['id', 'name', 'kind', 'primary_attribute', 'icon'], $gems->gems);
            $this->insert($db, 'catalog_gem_tag', ['gem_id', 'tag'], $gems->tags);
            $this->insert($db, 'catalog_gem_recommended_support', ['gem_id', 'support_id', 'rank'], $gems->recommendedSupports);
            $this->insert($db, 'catalog_gem_requirement', ['gem_id', 'term', 'mode', 'origin', 'clause'], $requirements);
        });
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function requirements(NormalizedGems $gems): array
    {
        $this->unparsed = [];
        $rows = [];

        foreach ($gems->supportTexts as $support) {
            $parsed = $this->parser->parse($support['text']);

            foreach ($parsed->requires as $term) {
                $rows[] = ['gem_id' => $support['id'], 'term' => $term, 'mode' => 'requires', 'origin' => 'parsed', 'clause' => $support['text']];
            }

            foreach ($parsed->excludes as $term) {
                $rows[] = ['gem_id' => $support['id'], 'term' => $term, 'mode' => 'excludes', 'origin' => 'parsed', 'clause' => $support['text']];
            }

            foreach ($parsed->unparsed as $clause) {
                $this->unparsed[] = ['clause' => $clause, 'gem' => $support['id']];
            }
        }

        return $rows;
    }

    /**
     * @param list<string>                     $columns
     * @param list<array<string, scalar|null>> $rows
     */
    private function insert(Connection $db, string $table, array $columns, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholder = '('.implode(', ', array_fill(0, \count($columns), '?')).')';

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $params[] = $row[$column] ?? null;
                }
            }

            $db->executeStatement(
                \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($chunk), $placeholder))),
                $params,
            );
        }
    }

    private function lastKnownRevision(): ?string
    {
        $value = $this->db->fetchOne("SELECT upstream_revision FROM catalog_sync WHERE source = 'skill_gems' AND status IN ('ok', 'unchanged') AND upstream_revision IS NOT NULL ORDER BY id DESC LIMIT 1");

        return \is_string($value) ? $value : null;
    }

    private function storedCount(): int
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM catalog_gem');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function record(SyncResult $result, ?string $upstreamRevision): SyncResult
    {
        $this->entityManager->persist(new CatalogSync(
            source: 'skill_gems',
            status: $result->status,
            count: $result->count,
            upstreamRevision: $upstreamRevision,
            error: $result->error,
        ));
        $this->entityManager->flush();

        return $result;
    }
}
