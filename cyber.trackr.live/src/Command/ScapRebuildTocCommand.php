<?php

namespace App\Command;

use App\Service\ScapTocBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wipes resources/data/scap_toc.json and re-parses every SCAP XCCDF XML
 * in resources/data/scap/ from scratch, populating the new sev: {h, m, l}
 * field on every entry.
 *
 * Mirrors app:stig:rebuild-toc. Run after dropping new SCAP files into
 * the data dir, or once after the schema changed (sev field introduction).
 */
#[AsCommand(
    name: 'app:scap:rebuild-toc',
    description: 'Re-parse all SCAP XCCDF XML files and rebuild scap_toc.json (including pre-computed severity counts).'
)]
class ScapRebuildTocCommand extends Command
{
    public function __construct(private ScapTocBuilder $tocBuilder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding scap_toc.json');
        $io->writeln('Source dir : ' . $this->tocBuilder->scapDir());
        $io->writeln('Target file: ' . $this->tocBuilder->tocPath());
        $io->newLine();

        $start = microtime(true);
        $count = 0;
        $progress = $io->createProgressBar();
        $progress->setFormat(' %current% files [%bar%] %elapsed:6s% %message%');
        $progress->setMessage('');
        $progress->start();

        $scaps = $this->tocBuilder->rebuildAll(function ($file) use ($progress, &$count) {
            $count++;
            $progress->setMessage($file->getFilename());
            $progress->advance();
        });

        $progress->finish();
        $io->newLine(2);

        $this->tocBuilder->writeToc($scaps);

        $titleCount = count($scaps);
        $instanceCount = array_sum(array_map('count', $scaps));
        $elapsed = number_format(microtime(true) - $start, 2);

        $io->success(sprintf(
            'Wrote %d unique titles (%d total version-instances) from %d XML files in %ss.',
            $titleCount,
            $instanceCount,
            $count,
            $elapsed
        ));

        return Command::SUCCESS;
    }
}
