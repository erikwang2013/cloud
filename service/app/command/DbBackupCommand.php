<?php
namespace App\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbBackupCommand extends Command
{
    protected static $defaultName = 'db:backup';
    protected static $defaultDescription = 'Backup database to SQL file';

    protected function configure(): void
    {
        $this->addOption('s3', null, InputOption::VALUE_NONE, 'Upload to S3 after backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting database backup...</info>');

        $dbConfig = config('database.connections.mysql');
        $backupDir = storage_path('backups');
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        $filename = $backupDir . '/backup_' . date('Ymd_His') . '.sql';
        // 密码经 MYSQL_PWD 传入而非 -p 参数，避免出现在进程 cmdline（ps 可读）
        $command  = sprintf(
            'MYSQL_PWD=%s mysqldump -h%s -u%s %s > %s 2>&1',
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['database']),
            escapeshellarg($filename)
        );

        exec($command, $output_lines, $exitCode);

        if ($exitCode !== 0) {
            $output->writeln('<error>Backup failed: ' . implode("\n", $output_lines) . '</error>');
            return Command::FAILURE;
        }

        $size = filesize($filename);
        $output->writeln("<info>Backup saved: {$filename} (" . round($size / 1024, 1) . " KB)</info>");

        // Optional S3 upload
        if ($input->getOption('s3')) {
            $output->writeln('<info>Uploading to S3...</info>');
            $s3Key     = getenv('AWS_ACCESS_KEY_ID');
            $s3Secret  = getenv('AWS_SECRET_ACCESS_KEY');
            $s3Bucket  = getenv('BACKUP_S3_BUCKET');
            $s3Region  = getenv('BACKUP_S3_REGION') ?: 'us-east-1';

            if ($s3Key && $s3Secret && $s3Bucket) {
                try {
                    $s3 = new \Aws\S3\S3Client([
                        'version'     => 'latest',
                        'region'      => $s3Region,
                        'credentials' => ['key' => $s3Key, 'secret' => $s3Secret],
                    ]);
                    $s3->putObject([
                        'Bucket' => $s3Bucket,
                        'Key'    => 'db-backups/' . basename($filename),
                        'Body'   => fopen($filename, 'r'),
                    ]);
                    $output->writeln('<info>S3 upload complete.</info>');
                } catch (\Throwable $e) {
                    $output->writeln('<comment>S3 upload failed: ' . $e->getMessage() . '</comment>');
                }
            }
        }

        // Clean up old backups (keep last 7 days)
        foreach (glob($backupDir . '/backup_*.sql') as $old) {
            if (filemtime($old) < time() - 86400 * 7) {
                unlink($old);
                $output->writeln("<comment>Removed old backup: {$old}</comment>");
            }
        }

        return Command::SUCCESS;
    }
}
