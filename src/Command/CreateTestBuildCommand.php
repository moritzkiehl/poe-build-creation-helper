<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CatalogSync;
use App\Interchange\BuildDocumentReader;
use App\Repository\BuildRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Creates a build and prints its edit path, so an end-to-end test can start
 * from a known state without clicking through an upload.
 *
 * It also seeds a synthetic corner of the passive tree catalog: a class with a
 * start node, a target node one edge away from it, a leaf that hangs off the
 * target (so the target is a junction whose removal must strand the leaf
 * too), and an island with no edge at all (so a click there is refused as not
 * connected). The real catalog is fetched from Grinding Gear Games' own
 * export by `app:catalog:sync`, and an end-to-end test has no business
 * downloading 18 MB from a third party or identifying this machine to it just
 * to click one pixel. Every id, name and coordinate here is invented.
 *
 * Test environment only: it hands out an edit token and writes catalog rows
 * that would corrupt real data outside a throwaway database.
 */
#[When(env: 'test')]
#[AsCommand(name: 'app:test:build', description: 'Create a build and print its edit path (test environment only)')]
final class CreateTestBuildCommand extends Command
{
    private const string CLASS_ID = 'e2e_class';
    private const string START_NODE_ID = 'e2e_start';
    private const string TARGET_NODE_ID = 'e2e_target';
    private const string LEAF_NODE_ID = 'e2e_leaf';
    private const string ISLAND_NODE_ID = 'e2e_island';

    // World units away from the start node. tree_controller.js centres the
    // camera on the start node at a fixed initial scale (see createCamera in
    // connect()), so this is what the end-to-end test's click offset is
    // computed from — the two must agree.
    private const float TARGET_OFFSET_X = 1000.0;

    // Hangs off the target, not off the start: e2e_target is the junction a
    // "removal cascades" test needs, so removing it must strand this leaf.
    private const float LEAF_OFFSET_X = 2000.0;

    // Carries no edge at all — the "an illegal node cannot be allocated"
    // test needs a node the start can never reach.
    private const float ISLAND_OFFSET_X = -1000.0;

    public function __construct(
        private readonly BuildRepository $builds,
        private readonly BuildDocumentReader $reader,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = file_get_contents(\dirname(__DIR__, 2).'/tests/fixtures/build/valid-full.build');

        if (false === $json) {
            $output->writeln('The fixture build could not be read.');

            return Command::FAILURE;
        }

        $this->seedTree();

        $build = $this->builds->create($this->reader->read($json), '0.5.5');
        // The fixture carries no class of its own; the canvas only knows
        // where to centre once one is chosen, so the seeded class is assigned
        // directly rather than through the header-edit command.
        $build->setClassKey(self::CLASS_ID);

        $this->entityManager->persist(new CatalogSync(source: 'passive_tree', status: 'ok', count: 4, gameVersion: '0.5.5'));
        $this->entityManager->flush();

        $output->writeln(\sprintf('/b/%s/edit/%s', $build->getShareSlug(), (string) $build->getEditToken()));

        return Command::SUCCESS;
    }

    /**
     * Clears the whole passive catalog before seeding, rather than deleting
     * only the two rows this command owns. PHPUnit fixtures from earlier
     * tasks leave stray `catalog_passive` rows behind — several of them at
     * world (0,0), the same point the seeded start node occupies — and a
     * leftover row there wins the canvas hit-test at centre before this
     * command's own start node does. This command runs `#[When(env: 'test')]`
     * only, so wiping the table never touches a development database, and
     * the end-to-end suite needs a deterministic tree far more than it needs
     * to coexist with unrelated fixture data.
     */
    private function seedTree(): void
    {
        $this->db->transactional(static function (Connection $db): void {
            $db->executeStatement('DELETE FROM catalog_passive_edge');
            $db->executeStatement('DELETE FROM catalog_passive');
            $db->executeStatement('DELETE FROM catalog_class');

            $db->executeStatement(
                'INSERT INTO catalog_class (id, start_node_id, base_str, base_dex, base_int, ascendancies) VALUES (?, ?, ?, ?, ?, ?)',
                [self::CLASS_ID, self::START_NODE_ID, 0, 0, 0, '[]'],
            );

            $db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [self::START_NODE_ID, 'End-to-end start', 'small', null, 0.0, 0.0, '[]', '[]'],
            );
            $db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                // The recipe ids are stored the way the real catalog stores
                // them — unspaced, carrying "Liquid" — and TreeExport runs
                // them through StatText::emotion() on the way out, exactly as
                // it does for a real Distilled Emotion id. Spacing them here
                // instead would let the fixture skip the transform the real
                // pipeline always applies.
                [self::TARGET_NODE_ID, 'End-to-end target', 'small', null, self::TARGET_OFFSET_X, 0.0, '["+10 to Strength"]', '["ConcentratedLiquidFear","LiquidIre","IsolatedLiquidEnvy"]'],
            );

            $db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [self::LEAF_NODE_ID, 'End-to-end leaf', 'small', null, self::LEAF_OFFSET_X, 0.0, '[]', '[]'],
            );
            $db->executeStatement(
                'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [self::ISLAND_NODE_ID, 'End-to-end island', 'small', null, self::ISLAND_OFFSET_X, 0.0, '[]', '[]'],
            );

            $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [self::START_NODE_ID, self::TARGET_NODE_ID]);
            // The leaf hangs off the target, not off the start, so the target
            // is a junction: removing it must strand the leaf too.
            $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [self::TARGET_NODE_ID, self::LEAF_NODE_ID]);
            // The island gets no edge at all: it touches nothing, on purpose.
        });
    }
}
