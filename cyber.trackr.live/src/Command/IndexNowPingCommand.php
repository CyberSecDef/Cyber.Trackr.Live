<?php

namespace App\Command;

use App\Service\IndexNowPinger;
use App\Service\StigTocBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Notify IndexNow (Bing / Yandex / Seznam / Naver) about URL changes.
 *
 *   # Ad-hoc — ping specific URLs (paths or absolute):
 *   php bin/console app:indexnow:ping /stig/Windows_11/1/4 /stig/feed.atom
 *
 *   # Recently-changed STIGs (last 30 days based on toc dates) plus the
 *   # surfaces that change with every deploy:
 *   php bin/console app:indexnow:ping --recent
 *
 *   # Just the always-changing surfaces (home, sitemap, feed, changelog):
 *   php bin/console app:indexnow:ping --core
 *
 * No-op + clean exit when no IndexNow key file is present under /public/,
 * so it's safe to wire into deploy scripts unconditionally.
 */
#[AsCommand(
    name: 'app:indexnow:ping',
    description: 'Notify IndexNow (Bing, Yandex, etc.) that URLs have changed.'
)]
class IndexNowPingCommand extends Command
{
    public function __construct(
        private IndexNowPinger $pinger,
        private StigTocBuilder $tocBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'urls',
                InputArgument::IS_ARRAY,
                'One or more URLs (relative paths or absolute) to ping.'
            )
            ->addOption(
                'recent',
                null,
                InputOption::VALUE_NONE,
                'Auto-ping STIG versions whose toc date is within --within days, plus always-changing surfaces.'
            )
            ->addOption(
                'within',
                null,
                InputOption::VALUE_REQUIRED,
                'Day window for --recent.',
                '30'
            )
            ->addOption(
                'core',
                null,
                InputOption::VALUE_NONE,
                'Ping just the always-changing surfaces (home, sitemap, atom feed, changelog, library indexes).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $urls = $input->getArgument('urls');
        if ($input->getOption('recent')) {
            $urls = array_merge($urls, $this->coreUrls(), $this->recentStigUrls((int) $input->getOption('within')));
        } elseif ($input->getOption('core')) {
            $urls = array_merge($urls, $this->coreUrls());
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            $io->warning('No URLs to ping. Pass URLs as arguments, or use --recent / --core.');
            return Command::SUCCESS;
        }

        $key = $this->pinger->discoverKey();
        if ($key === null) {
            $io->warning('No IndexNow key file under /public/. Skipping (this is fine — IndexNow is best-effort).');
            return Command::SUCCESS;
        }
        $io->note(sprintf('Using key from %s', $key['keyLocation']));

        $io->section(sprintf('Pinging IndexNow with %d URLs', count($urls)));
        if ($output->isVerbose()) {
            foreach ($urls as $u) $io->writeln('  · ' . $u);
        }

        $ok = $this->pinger->ping($urls);
        if ($ok) {
            $io->success(sprintf('IndexNow accepted %d URLs.', count($urls)));
            return Command::SUCCESS;
        }
        $io->warning('IndexNow ping did not succeed. See logs.');
        return Command::SUCCESS;  // best-effort — never fail a deploy because of this
    }

    /**
     * URLs that effectively change every deploy (home + the indexes).
     *
     * @return array<int,string>
     */
    private function coreUrls(): array
    {
        return [
            '/',
            '/stig',
            '/stig/index',
            '/scap',
            '/cci',
            '/rmf/4',
            '/rmf/5',
            '/rmf/4-to-5',
            '/baselines',
            '/plans',
            '/ckl-viewer',
            '/changelog',
            '/sitemap.xml',
            '/stig/feed.atom',
        ];
    }

    /**
     * STIG view URLs whose toc-recorded date falls within the lookback
     * window. Catches every newly-released or updated STIG so search
     * engines re-fetch them right away.
     *
     * @return array<int,string>
     */
    private function recentStigUrls(int $withinDays): array
    {
        $tocPath = $this->tocBuilder->tocPath();
        if (!is_file($tocPath)) return [];
        $stigs = json_decode((string) @file_get_contents($tocPath), true);
        if (!is_array($stigs)) return [];

        $cutoff = (new \DateTimeImmutable())->modify('-' . max(1, $withinDays) . ' days');
        $urls = [];
        foreach ($stigs as $title => $entries) {
            if (!is_array($entries)) continue;
            foreach ($entries as $e) {
                if (empty($e['version']) || empty($e['release']) || empty($e['date'])) continue;
                try {
                    $d = new \DateTimeImmutable((string) $e['date']);
                } catch (\Throwable) {
                    continue;
                }
                if ($d < $cutoff) continue;
                $urls[] = '/stig/' . rawurlencode((string) $title)
                       . '/' . rawurlencode((string) $e['version'])
                       . '/' . rawurlencode((string) $e['release']);
            }
        }
        return $urls;
    }
}
