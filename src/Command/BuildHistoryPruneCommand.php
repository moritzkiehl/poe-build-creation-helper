<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\BuildEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops edit history past the retention window. Named snapshots are kept
 * whatever their age — the user asked for those.
 *
 * A command rather than a scheduled job, like the catalog sync: deleting
 * someone's history should be something a person decided to do.
 */
#[AsCommand(name: 'app:build:history:prune', description: 'Delete edit history older than the retention window')]
final class BuildHistoryPruneCommand extends Command
{
    private const int DEFAULT_DAYS = 30;

    public function __construct(private readonly BuildEventRepository $events)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of history to keep', (string) self::DEFAULT_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $option = $input->getOption('days');
        $days = is_numeric($option) ? (int) $option : 0;

        if ($days < 1) {
            $io->error('Keep at least one day of history.');

            return Command::INVALID;
        }

        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', $days));
        $deleted = $this->events->prune($cutoff);

        $io->success(\sprintf('Deleted %d edit events from before %s. Named snapshots were kept.', $deleted, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
