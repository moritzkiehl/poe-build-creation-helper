<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Build;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds a star-shaped passive tree the legality rules accept: one start node,
 * with every given id connected to it directly, plus a `catalog_class` whose
 * start node is it.
 *
 * For a test that only needs "connected and legal" and does not care what
 * shape the tree is — most of the controller and kernel tests that allocate a
 * passive incidentally, on the way to testing something else entirely. A test
 * that cares about the tree's shape (a cascade, an unlock gate) seeds its own
 * graph instead; see `BuildEditorControllerTest::seedBuildWithTree()`.
 *
 * Every id, name and coordinate here is invented — this must never read from
 * or resemble `var/catalog/`, the gitignored real GGG data.
 */
trait SeedsALegalPassiveTree
{
    private const string LEGAL_TREE_START_NODE = 'test_legal_start';
    private const string LEGAL_TREE_CLASS = 'test_legal_class';

    /**
     * @param list<string> $ids the passive ids the test is about to allocate
     *
     * @return string the class id a build must carry to see this start node
     */
    private function seedALegalPassiveTree(Connection $db, array $ids): string
    {
        $allIds = [self::LEGAL_TREE_START_NODE, ...$ids];
        $placeholders = implode(',', array_fill(0, \count($allIds), '?'));

        $db->executeStatement('DELETE FROM catalog_passive_edge WHERE from_id = ? OR to_id = ?', [self::LEGAL_TREE_START_NODE, self::LEGAL_TREE_START_NODE]);
        $db->executeStatement("DELETE FROM catalog_passive WHERE id IN ({$placeholders})", $allIds);
        $db->executeStatement('DELETE FROM catalog_class WHERE id = ?', [self::LEGAL_TREE_CLASS]);

        $db->executeStatement(
            'INSERT INTO catalog_class (id, start_node_id, base_str, base_dex, base_int, ascendancies) VALUES (?, ?, 0, 0, 0, ?)',
            [self::LEGAL_TREE_CLASS, self::LEGAL_TREE_START_NODE, '[]'],
        );
        $db->executeStatement(
            "INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, 'small', NULL, 0, 0, '[]', '[]')",
            [self::LEGAL_TREE_START_NODE, self::LEGAL_TREE_START_NODE],
        );

        foreach ($ids as $id) {
            $db->executeStatement(
                "INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, 'small', NULL, 0, 0, '[]', '[]')",
                [$id, $id],
            );
            $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [self::LEGAL_TREE_START_NODE, $id]);
        }

        return self::LEGAL_TREE_CLASS;
    }

    /**
     * Assigns a build to a class whose start node reaches every given id
     * directly, so allocating them is legal. For a kernel test dispatching
     * `AllocatePassive` straight onto the bus, which has no `header.set`
     * action to go through the way an HTTP test does.
     *
     * @param list<string> $legalPassiveIds
     */
    private function buildWithLegalTree(Build $build, EntityManagerInterface $entityManager, array $legalPassiveIds): Build
    {
        $db = self::getContainer()->get(Connection::class);
        $classId = $this->seedALegalPassiveTree($db, $legalPassiveIds);

        $build->setClassKey($classId);
        $entityManager->flush();

        return $build;
    }
}
