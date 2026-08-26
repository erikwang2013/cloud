<?php
namespace App\command;

use Common\i18n\I18n;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class I18nSyncCommand extends Command
{
    protected static $defaultName = 'i18n:sync';
    protected static $defaultDescription = 'Scan code for I18n::trans() calls and report missing/extra keys';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locale = I18n::getLocale();
        $keys   = I18n::getKeys();
        $usedKeys = $this->scanSourceFiles();

        $output->writeln("<info>Locale:</info> {$locale}");
        $output->writeln("<info>Translation keys loaded:</info> " . count($keys));
        $output->writeln("<info>Keys used in source:</info> " . count($usedKeys));
        $output->writeln('');

        $missing = array_diff($usedKeys, $keys);
        $unused  = array_diff($keys, $usedKeys);

        if (!empty($missing)) {
            $output->writeln('<comment>Missing keys (used in code but not in messages.php):</comment>');
            foreach ($missing as $k) {
                $output->writeln("  - {$k}");
            }
        }

        if (!empty($unused)) {
            $output->writeln('');
            $output->writeln('<comment>Potentially unused keys (in messages.php but not in code):</comment>');
            foreach ($unused as $k) {
                $output->writeln("  - {$k}");
            }
        }

        if (empty($missing) && empty($unused)) {
            $output->writeln('<info>All keys are synced.</info>');
        }

        return Command::SUCCESS;
    }

    private function scanSourceFiles(): array
    {
        $keys = [];
        $dirs = [
            base_path() . '/app',
            base_path() . '/common',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') continue;
                $content = file_get_contents($file->getPathname());
                preg_match_all('/I18n::trans\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches);
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }
}
