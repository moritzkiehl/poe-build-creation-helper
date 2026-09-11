<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\PassiveTreeSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pulls the catalog from upstream. Deliberately a command and not a scheduled
 * job: adopting a game patch is a decision, and a broken upstream must not take
 * the running application down with it.
 */
#[AsCommand(name: 'app:catalog:sync', description: 'Fetch the game data the catalog is built from')]
final class CatalogSyncCommand extends Command
{
    public function __construct(private readonly PassiveTreeSync $passiveTree)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Catalog sync');

        $result = $this->passiveTree->run();

        if (!$result->ok) {
            $io->error(\sprintf('passive_tree: %s', $result->error ?? 'failed'));
            $io->note('The stored catalog was left untouched.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('passive_tree: %d nodes', $result->count));

        return Command::SUCCESS;
    }
}
