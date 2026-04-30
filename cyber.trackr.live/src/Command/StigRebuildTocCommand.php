<?php

namespace App\Command;

use App\Service\StigTocBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wipes resources/data/stig_toc.json and re-parses every STIG XML in
 * resources/data/stig/ from scratch, populating the new sev: {h, m, l}
 * field on every entry.
 *
 * Run after dropping new STIG files into the data dir, or once after
 * the schema changed (sev field introduction).
 */
#[AsCommand(
    name: 'app:stig:rebuild-toc',
    description: 'Re-parse all STIG XML files and rebuild stig_toc.json (including pre-computed severity counts).'
)]
class StigRebuildTocCommand extends Command
{
    public function __construct(private StigTocBuilder $tocBuilder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding stig_toc.json');
        $io->writeln('Source dir : ' . $this->tocBuilder->stigDir());
        $io->writeln('Target file: ' . $this->tocBuilder->tocPath());
        $io->newLine();

        $start = microtime(true);
        $count = 0;
        $progress = $io->createProgressBar();
        $progress->setFormat(' %current% files [%bar%] %elapsed:6s% %message%');
        $progress->setMessage('');
        $progress->start();

        $stigs = $this->tocBuilder->rebuildAll(function ($file) use ($progress, &$count) {
            $count++;
            $progress->setMessage($file->getFilename());
            $progress->advance();
        });

        $progress->finish();
        $io->newLine(2);

        $this->tocBuilder->writeToc($stigs);

        $titleCount = count($stigs);
        $instanceCount = array_sum(array_map('count', $stigs));
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
