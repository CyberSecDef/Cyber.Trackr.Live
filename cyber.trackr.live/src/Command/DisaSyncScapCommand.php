<?php

namespace App\Command;

use App\Service\DisaCatalogSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pulls the DISA cyber.mil catalog and downloads any SCAP Benchmark
 * bundles we don't already have. Extracts the XCCDF XML into
 * resources/data/scap/ and parks the original ZIP under
 * resources/data/zips/. Safe to re-run; existing files are skipped.
 *
 * Wired into bin/refresh-data.sh (with `|| true`) so a transient
 * cyber.mil outage doesn't fail the rest of the daily cron.
 */
#[AsCommand(
    name: 'app:disa:sync-scap',
    description: 'Sync new SCAP benchmark bundles from the DISA cyber.mil catalog into the local archive.'
)]
class DisaSyncScapCommand extends Command
{
    public function __construct(private DisaCatalogSyncer $syncer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE,
            'Fetch the catalog and report what would be downloaded, but write nothing to disk.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $io->title('Syncing SCAP benchmarks from DISA' . ($dryRun ? ' (dry-run)' : ''));

        $start = microtime(true);
        try {
            $stats = $this->syncer->sync(
                DisaCatalogSyncer::KIND_SCAP,
                fn(string $line) => $output->writeln($line),
                $dryRun,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $elapsed = microtime(true) - $start;

        $io->newLine();
        $io->definitionList(
            ['Candidates'    => (string) $stats['candidates']],
            ['Downloaded'    => (string) $stats['downloaded']],
            ['Skipped'       => (string) $stats['skipped']],
            ['XML extracted' => (string) $stats['xml_extracted']],
            ['Errors'        => (string) $stats['errors']],
            ['Elapsed'       => sprintf('%.1fs', $elapsed)],
        );
        $io->success('SCAP sync complete.');
        return Command::SUCCESS;
    }
}
