<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meta-command that runs every "STIG-derived corpus" rebuild in order.
 *
 * Use this after a bulk STIG/SCAP refresh (dropping new XML files into
 * resources/data/stig/ or scap/) to bring every dependent index up to
 * date in one shot:
 *
 *   1. app:stig:rebuild-toc   — wipes + rebuilds stig_toc.json
 *   2. app:scap:rebuild-toc   — wipes + rebuilds scap_toc.json
 *   3. app:vulns:rebuild-toc  — re-scans the corpus for IAVM/CTO/CVE refs
 *
 * Search index rebuild and KEV refresh are deliberately NOT included
 * here — `app:search:rebuild --full` is much slower and has its own
 * cadence, and KEV is independent of the STIG/SCAP corpus.
 */
#[AsCommand(
    name: 'app:stig:rebuild',
    description: 'Run app:stig:rebuild-toc, app:scap:rebuild-toc, and app:vulns:rebuild-toc in order.'
)]
class StigRebuildCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding the STIG/SCAP-derived corpus');

        $start = microtime(true);
        $steps = [
            'app:stig:rebuild-toc',
            'app:scap:rebuild-toc',
            'app:vulns:rebuild-toc',
        ];

        $app = $this->getApplication();
        if ($app === null) {
            $io->error('No console application bound — cannot dispatch sub-commands.');
            return Command::FAILURE;
        }

        foreach ($steps as $i => $name) {
            $io->section(sprintf('[%d/%d] %s', $i + 1, count($steps), $name));
            $cmd = $app->find($name);
            $exit = $cmd->run(new ArrayInput([]), $output);
            if ($exit !== Command::SUCCESS) {
                $io->error(sprintf('%s failed (exit %d) — stopping.', $name, $exit));
                return $exit;
            }
        }

        $elapsed = number_format(microtime(true) - $start, 2);
        $io->success("STIG/SCAP/Vulns rebuild complete in {$elapsed}s.");
        return Command::SUCCESS;
    }
}
