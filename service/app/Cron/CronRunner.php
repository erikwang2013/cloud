<?php
namespace App\Cron;

use Workerman\Timer;
use Workerman\Worker;

/**
 * Cron worker process: evaluates config/cron.php 5-field cron expressions
 * every minute and invokes matching [Class, method] tasks.
 */
class CronRunner
{
    protected array $tasks = [];

    public function __construct()
    {
        $this->tasks = config('cron', []);
    }

    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(60, function () {
            $now = getdate();
            foreach ($this->tasks as $expr => $callable) {
                if ($this->match($expr, $now)) {
                    $this->runTask($callable);
                }
            }
        });
    }

    protected function match(string $expr, array $now): bool
    {
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) {
            return false;
        }

        $fields = [
            $this->field($parts[0], $now['minutes']),
            $this->field($parts[1], $now['hours']),
            $this->field($parts[2], $now['mday']),
            $this->field($parts[3], $now['mon']),
            $this->field($parts[4], $now['wday'] ?: 7), // PHP wday 0=Sunday -> cron 7
        ];

        return !in_array(false, $fields, true);
    }

    protected function field(string $expr, int $value): bool
    {
        foreach (explode(',', $expr) as $part) {
            if ($part === '*') {
                return true;
            }
            if (str_contains($part, '/')) {
                [$base, $step] = explode('/', $part, 2);
                if (($base === '*' || (int) $base === $value) && $value % (int) $step === 0) {
                    return true;
                }
            } elseif (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                if ($value >= (int) $a && $value <= (int) $b) {
                    return true;
                }
            } elseif ((int) $part === $value) {
                return true;
            }
        }
        return false;
    }

    protected function runTask(array $callable): void
    {
        try {
            [$class, $method] = $callable;
            $instance = new $class();
            $instance->$method();
        } catch (\Throwable $e) {
            error_log('[cron] Task failed ' . json_encode($callable) . ': ' . $e->getMessage());
        }
    }
}
