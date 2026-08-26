<?php

namespace App\command;

use support\MigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateRollbackCommand extends Command
{
    protected static $defaultName = 'migrate:rollback';
    protected static $defaultDescription = 'Rollback the last database migration batch';

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = new MigrationRunner(base_path('database/migrations'));
        $runner->setOutput($output);
        $runner->rollback();
        return Command::SUCCESS;
    }
}
