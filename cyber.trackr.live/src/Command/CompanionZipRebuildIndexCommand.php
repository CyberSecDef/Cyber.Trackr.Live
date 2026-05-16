<?php

namespace App\Command;

use App\Service\StigCompanionZipFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds resources/data/companion_zip_index.json by scanning every ZIP in
 * resources/data/zips/ and matching the XCCDF XML inside it back to a
 * stig_toc.json entry. Lets us link STIG pages to DISA companion bundles
 * even when DISA's ZIP filenames use abbreviated/non-canonical names
 * (e.g. "U_ASD_V6R4_STIG.zip" for "Application Security and Development").
 *
 * Cheap (~5s for 1200 ZIPs). Safe to re-run; output is deterministic.
 * Wired into bin/refresh-data.sh so the daily cron keeps it current.
 */
#[AsCommand(
    name: 'app:companion-zip:rebuild-index',
    description: 'Rebuild the STIG → companion ZIP index by scanning XCCDF XMLs inside each ZIP.'
)]
class CompanionZipRebuildIndexCommand extends Command
{
    public function __construct(private StigCompanionZipFinder $finder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding STIG companion-ZIP index');
        $io->writeln('Target : ' . $this->finder->indexPath());
        $io->newLine();

        $start = microtime(true);
        $stats = $this->finder->rebuildIndex();
        $elapsed = microtime(true) - $start;

        $io->definitionList(
            ['ZIPs scanned'                  => (string) $stats['zips_scanned']],
            ['ZIPs with a known STIG XML'    => (string) $stats['zips_matched']],
            ['ZIPs unmatched (SCAP/Ansible)' => (string) $stats['zips_unmatched']],
            ['STIG versions in toc'          => (string) $stats['stigs_total']],
            ['STIG versions covered'         => (string) $stats['stigs_covered']],
            ['Elapsed'                       => sprintf('%.2fs', $elapsed)],
        );

        $io->success('Index rebuilt.');
        return Command::SUCCESS;
    }
}
