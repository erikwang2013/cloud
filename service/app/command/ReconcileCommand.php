<?php
namespace App\command;

use App\cron\PaymentReconcile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcileCommand extends Command
{
    protected static $defaultName = 'payment:reconcile';
    protected static $defaultDescription = 'Reconcile local transactions against channel reports for a date (default: today)';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)->setDescription(self::$defaultDescription);
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Reconcile date (Y-m-d)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = $input->getOption('date') ?: date('Y-m-d');
        try {
            (new PaymentReconcile())->run($date);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
