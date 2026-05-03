<?php

namespace App\Command;

use App\Service\Vulns\VulnsTocBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks every STIG and SCAP XML file under resources/data/ and extracts
 * IAVM / CTO / CVE references into a single resources/data/vulns_toc.json.
 *
 * Run after a STIG or SCAP refresh; mirrors the pattern of
 * app:stig:rebuild-toc / app:scap:rebuild-toc. Cheap to run (under a
 * minute on the full corpus) so you can re-run it freely.
 */
#[AsCommand(
    name: 'app:vulns:rebuild-toc',
    description: 'Scan STIG + SCAP XML for IAVM/CTO/CVE refs and rebuild vulns_toc.json.'
)]
class VulnsRebuildTocCommand extends Command
{
    public function __construct(private VulnsTocBuilder $builder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding vulns_toc.json');
        $io->writeln('STIG dir   : ' . $this->builder->stigDir());
        $io->writeln('SCAP dir   : ' . $this->builder->scapDir());
        $io->writeln('Target file: ' . $this->builder->tocPath());
        $io->newLine();

        $start = microtime(true);
        $count = 0;
        $progress = $io->createProgressBar();
        $progress->setFormat(' %current% files [%bar%] %elapsed:6s% %message%');
        $progress->setMessage('');
        $progress->start();

        $toc = $this->builder->rebuildAll(function ($file) use ($progress, &$count) {
            $count++;
            $progress->setMessage($file->getFilename());
            $progress->advance();
        });

        $progress->finish();
        $io->newLine(2);

        $this->builder->writeToc($toc);

        $stats = $toc['stats'];
        $elapsed = number_format(microtime(true) - $start, 2);

        $io->success(sprintf('Wrote vulns_toc.json in %ss after scanning %d XML files.', $elapsed, $count));
        $io->table(
            ['Metric', 'Value'],
            [
                ['STIG XML files scanned',     $stats['stig_files_scanned']],
                ['SCAP XML files scanned',     $stats['scap_files_scanned']],
                ['Rules with any match',       $stats['rules_with_any_match']],
                ['Rules w/ IAVA prose only',   $stats['rules_with_iavm_prose']],
                ['Distinct IAVM IDs',          $stats['distinct_iavms']],
                ['Distinct CTO IDs',           $stats['distinct_ctos']],
                ['Distinct CVEs',              $stats['distinct_cves']],
            ]
        );

        return Command::SUCCESS;
    }
}
