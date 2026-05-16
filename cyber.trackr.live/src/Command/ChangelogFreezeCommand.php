<?php

namespace App\Command;

use App\Service\Changelog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Freeze the project's git log to a JSON file the /changelog page can serve
 * from environments where `.git` isn't available (rsync deploys, container
 * builds, etc.).
 *
 * Usage:
 *
 *     php bin/console app:changelog:freeze
 *
 * Writes resources/data/changelog.json. The Changelog service prefers this
 * file when present, falling back to live `git log` for dev shells where the
 * repo is intact.
 *
 * Called automatically from bin/ship.sh between the toc rebuilds and
 * the rsync. The JSON is gitignored — it's generated, not authored.
 */
#[AsCommand(
    name: 'app:changelog:freeze',
    description: 'Freeze the git log to resources/data/changelog.json so the /changelog page works on prod (rsync deploys lose .git).'
)]
class ChangelogFreezeCommand extends Command
{
    public function __construct(
        private string $projectDir,
        private Changelog $changelog,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entries = $this->changelog->fetchFromGit();
        if ($entries === null) {
            $io->error('Could not read git log. Either git is not on PATH or .git is not reachable.');
            return Command::FAILURE;
        }

        $path = $this->projectDir . '/resources/data/changelog.json';
        $payload = [
            'frozen_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'entries'   => $entries,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $io->error('Failed to encode changelog as JSON.');
            return Command::FAILURE;
        }

        if (@file_put_contents($path, $json . "\n") === false) {
            $io->error("Failed to write {$path}");
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Wrote %d entries to %s (%.1f KB).',
            count($entries),
            $path,
            strlen($json) / 1024,
        ));
        return Command::SUCCESS;
    }
}
