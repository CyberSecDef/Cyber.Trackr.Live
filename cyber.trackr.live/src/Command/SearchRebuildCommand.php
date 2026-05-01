<?php

namespace App\Command;

use App\Service\Search\IndexBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds (or syncs deltas to) the inverted search index.
 *
 *   php bin/console app:search:rebuild           # incremental (default)
 *   php bin/console app:search:rebuild --full    # wipe + rebuild from scratch
 *
 * Auto-triggered by the STIG/SCAP DISA pull endpoints. Run manually after
 * dropping new overlay files into resources/data/overlays/, or whenever
 * the index drifts from reality.
 */
#[AsCommand(
    name: 'app:search:rebuild',
    description: 'Build or sync the inverted search index from current data sources.'
)]
class SearchRebuildCommand extends Command
{
    public function __construct(private IndexBuilder $builder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Wipe and rebuild from scratch.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $full = (bool) $input->getOption('full');

        $io->title($full ? 'Full search index rebuild' : 'Search index sync (deltas)');

        $start = microtime(true);
        $stats = $full ? $this->builder->buildAll() : $this->builder->syncDeltas();
        $elapsed = microtime(true) - $start;

        $io->definitionList(
            ['Docs' => number_format($stats['docs'])],
            ['Tokens' => number_format($stats['tokens'])],
            ['Trigrams' => number_format($stats['trigrams'])],
            ['Shards written' => $stats['shards_written'] ?? '—'],
            ['Sources unchanged' => $stats['unchanged']],
            ['Sources changed' => $stats['changed']],
            ['Sources added' => $stats['new']],
            ['Sources removed' => $stats['removed']],
            ['Elapsed' => sprintf('%.2fs', $elapsed)],
        );

        $io->success('Index rebuilt.');
        return Command::SUCCESS;
    }
}
