<?php

namespace app\command;

use app\common\MigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';
    protected static $defaultDescription = 'Run database migrations';

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = new MigrationRunner(base_path('database/migrations'));
        $runner->setOutput($output);
        $runner->migrate();
        return Command::SUCCESS;
    }
}
