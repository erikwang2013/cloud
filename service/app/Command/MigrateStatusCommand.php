<?php

namespace App\Command;

use support\MigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateStatusCommand extends Command
{
    protected static $defaultName = 'migrate:status';
    protected static $defaultDescription = 'Show migration status';

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = new MigrationRunner(base_path('database/migrations'));
        $runner->setOutput($output);
        $rows = $runner->status();

        if (empty($rows)) {
            $output->writeln('No migrations found.');
            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $tag = $row['status'] === 'Ran' ? 'info' : 'comment';
            $output->writeln("<{$tag}>{$row['status']}</{$tag}>  {$row['name']}");
        }
        return Command::SUCCESS;
    }
}
