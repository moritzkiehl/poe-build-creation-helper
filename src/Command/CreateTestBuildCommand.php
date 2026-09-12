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
 * start node, and one more node a known distance away. The real catalog is
 * fetched from Grinding Gear Games' own export by `app:catalog:sync`, and an
 * end-to-end test has no business downloading 18 MB from a third party or
 * identifying this machine to it just to click one pixel. Every id, name and
 * coordinate here is invented.
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

    // World units away from the start node. tree_controller.js centres the
    // camera on the start node at a fixed initial scale (see createCamera in
    // connect()), so this is what the end-to-end test's click offset is
    // computed from — the two must agree.
    private const float TARGET_OFFSET_X = 1000.0;

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

        $this->entityManager->persist(new CatalogSync(source: 'passive_tree', status: 'ok', count: 2, gameVersion: '0.5.5'));
        $this->entityManager->flush();

        $output->writeln(\sprintf('/b/%s/edit/%s', $build->getShareSlug(), (string) $build->getEditToken()));

        return Command::SUCCESS;
    }

    /**
     * Replaces the two rows this command owns rather than the whole catalog,
     * so a real `app:catalog:sync` run against the same database (there is no
     * reason to do one in this environment, but nothing stops it) is not
     * clobbered by re-running the end-to-end test.
     */
    private function seedTree(): void
    {
        $this->db->transactional(static function (Connection $db): void {
            $db->executeStatement('DELETE FROM catalog_passive_edge WHERE from_id = ? OR to_id = ?', [self::START_NODE_ID, self::START_NODE_ID]);
            $db->executeStatement('DELETE FROM catalog_passive WHERE id IN (?, ?)', [self::START_NODE_ID, self::TARGET_NODE_ID]);
            $db->executeStatement('DELETE FROM catalog_class WHERE id = ?', [self::CLASS_ID]);

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
                [self::TARGET_NODE_ID, 'End-to-end target', 'small', null, self::TARGET_OFFSET_X, 0.0, '["+10 to Strength"]', '["Concentrated Fear","Concentrated Ire","Concentrated Envy"]'],
            );

            $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [self::START_NODE_ID, self::TARGET_NODE_ID]);
        });
    }
}
