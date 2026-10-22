<?php

namespace App\Command;

use App\Service\StigDigestBuilder;
use App\Service\StigTocBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes every missing or stale "what changed since the previous version"
 * sidecar (resources/data/stig/*.digest.json). Requests only read them
 * (review P12), so they are built here, as part of app:stig:rebuild, right
 * after stig_toc.json is current.
 *
 * Idempotent: a sidecar that still matches both XMLs is left alone. A full
 * cold build is ~3,000 digests, about 16 s locally.
 */
#[AsCommand(
    name: 'app:stig:rebuild-digests',
    description: 'Write missing or stale STIG version digests (*.digest.json) from stig_toc.json.'
)]
class StigRebuildDigestsCommand extends Command
{
    public function __construct(
        private readonly StigDigestBuilder $digests,
        private readonly StigTocBuilder $toc,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rebuilding STIG version digests');

        $toc = json_decode((string) @file_get_contents($this->toc->tocPath()), true);
        if (!is_array($toc)) {
            $io->error('stig_toc.json is missing or unreadable: run app:stig:rebuild-toc first.');
            return Command::FAILURE;
        }

        $start = microtime(true);
        $stats = $this->digests->writeAllSidecars($this->toc->stigDir(), $toc);

        $io->definitionList(
            ['Written'                 => (string) $stats['built']],
            ['Already current'         => (string) $stats['fresh']],
            ['No previous version'     => (string) $stats['none']],
            ['Failed to write'         => (string) $stats['failed']],
            ['Elapsed'                 => sprintf('%.2fs', microtime(true) - $start)],
        );

        if ($stats['failed'] > 0) {
            $io->error('Some digests could not be written; those pages compute theirs per request.');
            return Command::FAILURE;
        }
        $io->success('Digests current.');
        return Command::SUCCESS;
    }
}
