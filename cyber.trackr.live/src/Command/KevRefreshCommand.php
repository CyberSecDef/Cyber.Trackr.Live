<?php

namespace App\Command;

use App\Service\Vulns\KevLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pulls the latest CISA KEV catalog and writes it to
 * resources/data/kev/known_exploited_vulnerabilities.json.
 *
 * CISA publishes the catalog as a stable JSON URL with no auth required:
 *   https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json
 *
 * Run before each deploy (or on a cron) to keep the local mirror fresh.
 * The site reads only the local file; no live HTTP call happens at request
 * time.
 */
#[AsCommand(
    name: 'app:kev:refresh',
    description: 'Pull the CISA Known Exploited Vulnerabilities catalog and mirror it locally.'
)]
class KevRefreshCommand extends Command
{
    private const FEED_URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';

    public function __construct(private KevLoader $loader)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Refreshing CISA KEV catalog');

        $target = $this->loader->catalogPath();
        $io->writeln('Source : ' . self::FEED_URL);
        $io->writeln('Target : ' . $target);
        $io->newLine();

        $start = microtime(true);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: cyber.trackr.live KEV refresh\r\nAccept: application/json\r\n",
                'timeout' => 30,
            ],
        ]);
        $body = @file_get_contents(self::FEED_URL, false, $ctx);
        if ($body === false) {
            $io->error('Failed to fetch the KEV feed. Check network connectivity and try again.');
            return Command::FAILURE;
        }

        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !isset($parsed['vulnerabilities']) || !is_array($parsed['vulnerabilities'])) {
            $io->error('Fetched body did not parse as the expected KEV JSON shape.');
            return Command::FAILURE;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->error("Could not create target directory: $dir");
            return Command::FAILURE;
        }

        // Re-encode pretty so the on-disk file is diff-friendly under git.
        $written = file_put_contents(
            $target,
            json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        if ($written === false) {
            $io->error("Could not write to $target");
            return Command::FAILURE;
        }

        $elapsed = number_format(microtime(true) - $start, 2);
        $count = count($parsed['vulnerabilities']);
        $version = (string) ($parsed['catalogVersion'] ?? 'unknown');
        $released = (string) ($parsed['dateReleased'] ?? 'unknown');

        $io->success(sprintf(
            'KEV catalog refreshed in %ss — %d entries, version %s, dateReleased %s',
            $elapsed,
            $count,
            $version,
            $released
        ));
        $io->writeln(sprintf('File size: %s bytes', number_format(filesize($target))));

        return Command::SUCCESS;
    }
}
