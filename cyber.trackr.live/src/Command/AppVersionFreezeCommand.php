<?php

namespace App\Command;

use App\Service\AppVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Live-computes the version string and writes it to <project>/VERSION so
 * production environments without `.git` (and without `git` on PATH) can
 * still render the footer correctly.
 *
 * Run from CI / deploy after the working tree is checked out:
 *   php bin/console app:version:freeze
 */
#[AsCommand(
    name: 'app:version:freeze',
    description: 'Compute the current version (MAJOR.YYMM.COMMITS) and write it to VERSION for production reads.'
)]
class AppVersionFreezeCommand extends Command
{
    public function __construct(
        private AppVersion $version,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $version = $this->version->compute();
        $path = $this->projectDir . '/VERSION';

        if (@file_put_contents($path, $version . "\n") === false) {
            $io->error("Could not write to $path");
            return Command::FAILURE;
        }

        $io->success("Wrote $version to $path");
        return Command::SUCCESS;
    }
}
