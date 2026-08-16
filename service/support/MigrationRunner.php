<?php

namespace support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationRunner
{
    private string $path;
    private string $table = 'migrations';
    private ?OutputInterface $output = null;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function migrate(): array
    {
        $this->ensureTable();

        if (!$this->acquireLock()) {
            $this->warn('Migration is already running. Skipping.');
            return [];
        }

        try {
            $files = $this->pendingFiles();
            if (empty($files)) {
                $this->info('Nothing to migrate.');
                return [];
            }

            $batch = $this->nextBatch();
            $ran = [];

            foreach ($files as $file) {
                $this->info("Migrating: {$file['name']}");
                $instance = $this->resolve($file['path']);

                Capsule::connection()->beginTransaction();
                try {
                    if ($instance !== null) {
                        $instance->up();
                    }
                    $this->record($file['name'], $batch);
                    // MySQL DDL 隐式提交会结束事务（transactionLevel 仍为 1），以 PDO 实际状态为准
                    if (Capsule::connection()->getPdo()->inTransaction()) {
                        Capsule::connection()->commit();
                    }
                    $ran[] = $file['name'];
                } catch (\Throwable $e) {
                    if (Capsule::connection()->getPdo()->inTransaction()) {
                        Capsule::connection()->rollBack();
                    }
                    throw $e;
                }
            }

            $this->info('Migrated: ' . count($ran) . ' file(s)');
            return $ran;
        } finally {
            $this->releaseLock();
        }
    }

    public function rollback(): array
    {
        $this->ensureTable();

        if (!$this->acquireLock()) {
            $this->warn('Rollback is already running. Skipping.');
            return [];
        }

        try {
            $lastBatch = Capsule::table($this->table)->max('batch');
            if (!$lastBatch) {
                $this->info('Nothing to rollback.');
                return [];
            }

            $rows = Capsule::table($this->table)
                ->where('batch', $lastBatch)
                ->orderBy('id', 'desc')
                ->get();

            $rolled = [];
            foreach ($rows as $row) {
                $filePath = $this->path . '/' . $row->migration . '.php';
                if (!is_file($filePath)) {
                    $this->warn("Migration file not found: {$row->migration}");
                    continue;
                }
                $this->info("Rolling back: {$row->migration}");
                $instance = $this->resolve($filePath);

                Capsule::connection()->beginTransaction();
                try {
                    if ($instance !== null) {
                        $instance->down();
                    }
                    Capsule::table($this->table)->where('id', $row->id)->delete();
                    // MySQL DDL 隐式提交会结束事务（transactionLevel 仍为 1），以 PDO 实际状态为准
                    if (Capsule::connection()->getPdo()->inTransaction()) {
                        Capsule::connection()->commit();
                    }
                    $rolled[] = $row->migration;
                } catch (\Throwable $e) {
                    if (Capsule::connection()->getPdo()->inTransaction()) {
                        Capsule::connection()->rollBack();
                    }
                    throw $e;
                }
            }

            $this->info('Rolled back: ' . count($rolled) . ' file(s)');
            return $rolled;
        } finally {
            $this->releaseLock();
        }
    }

    public function status(): array
    {
        $this->ensureTable();
        $all = $this->migrationFiles();
        $ran = Capsule::table($this->table)->pluck('migration')->toArray();
        $result = [];

        foreach ($all as $file) {
            $result[] = [
                'name' => $file['name'],
                'status' => in_array($file['name'], $ran) ? 'Ran' : 'Pending',
            ];
        }
        return $result;
    }

    private function acquireLock(): bool
    {
        $name = 'migration_lock_' . crc32($this->path);
        $result = Capsule::select("SELECT GET_LOCK(?, 0) AS acquired", [$name]);
        return !empty($result) && (int) $result[0]->acquired === 1;
    }

    private function releaseLock(): void
    {
        $name = 'migration_lock_' . crc32($this->path);
        Capsule::select("SELECT RELEASE_LOCK(?)", [$name]);
    }

    private function ensureTable(): void
    {
        if (Capsule::schema()->hasTable($this->table)) {
            return;
        }
        Capsule::schema()->create($this->table, function ($table) {
            $table->bigIncrements('id');
            $table->string('migration', 512);
            $table->integer('batch');
        });
    }

    private function pendingFiles(): array
    {
        $ran = Capsule::table($this->table)->pluck('migration')->toArray();
        return array_filter($this->migrationFiles(), fn($f) => !in_array($f['name'], $ran));
    }

    private function migrationFiles(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }
        $files = [];
        foreach (glob($this->path . '/*.php') as $filePath) {
            $name = basename($filePath, '.php');
            $files[] = ['name' => $name, 'path' => $filePath];
        }
        usort($files, fn($a, $b) => $a['name'] <=> $b['name']);
        return $files;
    }

    private function resolve(string $filePath): ?object
    {
        $instance = require $filePath;
        // 无 return 的旧式直接执行文件，require 返回 int(1)（PHP 约定）或显式裸 return 的 null
        if ($instance === 1 || $instance === null) {
            return null;
        }
        if (!$instance instanceof \support\Migration) {
            throw new \RuntimeException("Migration file must return a \support\Migration instance or execute directly: $filePath");
        }
        return $instance;
    }

    private function record(string $name, int $batch): void
    {
        Capsule::table($this->table)->insert([
            'migration' => $name,
            'batch' => $batch,
        ]);
    }

    private function nextBatch(): int
    {
        return (int) Capsule::table($this->table)->max('batch') + 1;
    }

    private function info(string $msg): void
    {
        if ($this->output) {
            $this->output->writeln("<info>$msg</info>");
        }
    }

    private function warn(string $msg): void
    {
        if ($this->output) {
            $this->output->writeln("<comment>$msg</comment>");
        }
    }
}
