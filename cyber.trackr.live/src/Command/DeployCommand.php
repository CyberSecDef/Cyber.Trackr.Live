<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wrapper for the standard post-deploy steps. Runs:
 *
 *   1. cache:clear        - regenerates compiled Twig/route/container so the
 *                           freshly-pulled code actually serves.
 *   2. app:search:rebuild - syncs the inverted index (delta by default;
 *                           use --full to wipe and rebuild from scratch).
 *
 * Inherits the current APP_ENV; on a prod server this is typically already
 * `prod`, so cache:clear hits the prod kernel without needing an --env flag.
 * From a non-prod shell, prefix with `APP_ENV=prod` explicitly.
 */
#[AsCommand(
    name: 'app:deploy',
    description: 'Post-deploy: clear caches and sync the search index.'
)]
class DeployCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'full',
            null,
            InputOption::VALUE_NONE,
            'Wipe and rebuild the search index from scratch instead of syncing deltas.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $app = $this->getApplication();
        if ($app === null) {
            $io->error('Application context not available.');
            return Command::FAILURE;
        }

        $io->title('Running deploy steps');

        $io->section('1/2 — Clearing cache');
        $code = $app->find('cache:clear')->run(
            new ArrayInput(['command' => 'cache:clear']),
            $output,
        );
        if ($code !== Command::SUCCESS) {
            $io->error('cache:clear failed.');
            return $code;
        }

        $io->section('2/2 — Syncing search index');
        $args = ['command' => 'app:search:rebuild'];
        if ($input->getOption('full')) {
            $args['--full'] = true;
        }
        $code = $app->find('app:search:rebuild')->run(new ArrayInput($args), $output);
        if ($code !== Command::SUCCESS) {
            $io->error('app:search:rebuild failed.');
            return $code;
        }

        $io->success('Deploy complete.');
        return Command::SUCCESS;
    }
}
