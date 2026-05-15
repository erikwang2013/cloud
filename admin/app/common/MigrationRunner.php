<?php

namespace app\common;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
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
            $instance->up();
            $this->record($file['name'], $batch);
            $ran[] = $file['name'];
        }

        $this->info('Migrated: ' . count($ran) . ' file(s)');
        return $ran;
    }

    public function rollback(): array
    {
        $this->ensureTable();
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
            $instance->down();
            Capsule::table($this->table)->where('id', $row->id)->delete();
            $rolled[] = $row->migration;
        }

        $this->info('Rolled back: ' . count($rolled) . ' file(s)');
        return $rolled;
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

    private function ensureTable(): void
    {
        if (Capsule::schema()->hasTable($this->table)) {
            return;
        }
        Capsule::schema()->create($this->table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('migration');
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

    private function resolve(string $filePath): object
    {
        $instance = require $filePath;
        if (!$instance instanceof \app\common\Migration) {
            throw new \RuntimeException("Migration file must return a \app\common\Migration instance: $filePath");
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
