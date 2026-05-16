<?php

namespace App\Command;

use App\Service\BulkDownloadIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds resources/data/bulk_download_index.json — one row per STIG
 * version in the toc, with XML path/size and (optional) companion ZIP
 * basename/size. Powers the bulk-download table on /stig/bulk so it
 * doesn't have to filesize() thousands of files per page render.
 *
 * Wired into bin/refresh-data.sh; depends on companion_zip_index.json
 * existing first, so run order matters in that script.
 */
#[AsCommand(
    name: 'app:bulk-download:rebuild-index',
    description: 'Rebuild the per-row XML/ZIP presence + size index used by /stig/bulk.'
)]
class BulkDownloadRebuildIndexCommand extends Command
{
    public function __construct(private BulkDownloadIndex $index)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding bulk-download index');
        $io->writeln('Target : ' . $this->index->indexPath());
        $io->newLine();

        $start = microtime(true);
        $stats = $this->index->rebuild();
        $elapsed = microtime(true) - $start;

        $io->definitionList(
            ['STIG versions'        => (string) $stats['entries']],
            ['XML files present'    => (string) $stats['xml_present']],
            ['ZIP files present'    => (string) $stats['zip_present']],
            ['Total XML bytes'      => $this->fmt($stats['xml_bytes'])],
            ['Total ZIP bytes'      => $this->fmt($stats['zip_bytes'])],
            ['Elapsed'              => sprintf('%.2fs', $elapsed)],
        );

        $io->success('Bulk-download index rebuilt.');
        return Command::SUCCESS;
    }

    private function fmt(int $bytes): string
    {
        if ($bytes >= 1 << 30) return sprintf('%.2f GB', $bytes / (1 << 30));
        if ($bytes >= 1 << 20) return sprintf('%.2f MB', $bytes / (1 << 20));
        if ($bytes >= 1 << 10) return sprintf('%.2f KB', $bytes / (1 << 10));
        return sprintf('%d B', $bytes);
    }
}
