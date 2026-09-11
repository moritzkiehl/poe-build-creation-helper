<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\GemSync;
use App\Catalog\ItemSync;
use App\Catalog\PassiveTreeSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
    public function __construct(
        private readonly PassiveTreeSync $passiveTree,
        private readonly GemSync $gems,
        private readonly ItemSync $items,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('report-unparsed', null, InputOption::VALUE_NONE, 'List every support clause the requirement parser could not read');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Catalog sync');

        $failed = false;

        foreach (['passive_tree' => $this->passiveTree->run(...), 'skill_gems' => $this->gems->run(...), 'items' => $this->items->run(...)] as $source => $run) {
            $result = $run();

            if (!$result->ok) {
                $io->error(\sprintf('%s: %s', $source, $result->error ?? 'failed'));
                $failed = true;
                continue;
            }

            $io->writeln(\sprintf('  %-14s %-9s %d', $source, $result->status, $result->count));
        }

        if ($failed) {
            $io->note('Sources that failed were left exactly as they were; the application keeps working on the data it already had.');

            return Command::FAILURE;
        }

        $unparsed = $this->gems->unparsedClauses();
        if ([] !== $unparsed) {
            $io->newLine();
            $io->warning(\sprintf('%d support clauses could not be read as requirements. That is why findings from this parser are warnings and never errors.', \count($unparsed)));

            if (true === $input->getOption('report-unparsed')) {
                $io->listing(array_map(static fn (array $u): string => $u['clause'], $unparsed));
            } else {
                $io->comment('Run with --report-unparsed to list them.');
            }
        }

        $io->success('Catalog synced.');

        return Command::SUCCESS;
    }
}
