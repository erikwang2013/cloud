<?php
namespace App\controller;

use Common\helper\Response;
use Illuminate\Support\Facades\Redis;

class StatusController
{
    public function index()
    {
        $components = [
            ['id' => 'api',     'name' => 'API Service',    'status' => 'operational'],
            ['id' => 'db',      'name' => 'Database',       'status' => 'operational'],
            ['id' => 'redis',   'name' => 'Redis Cache',    'status' => 'operational'],
            ['id' => 'payment', 'name' => 'Payment Gateway','status' => 'operational'],
            ['id' => 'provision','name' => 'Provisioning',  'status' => 'operational'],
        ];

        // Quick health checks
        try { \Illuminate\Database\Capsule\Manager::connection()->getPdo(); }
        catch (\Throwable $e) { $components[1]['status'] = 'degraded'; }

        try { \Illuminate\Support\Facades\Redis::ping(); }
        catch (\Throwable $e) { $components[2]['status'] = 'degraded'; }

        return json(Response::success([
            'overall'    => $this->overallStatus($components),
            'components' => $components,
            'updated_at' => date('c'),
        ]));
    }

    private function overallStatus(array $components): string
    {
        foreach ($components as $c) {
            if ($c['status'] === 'major_outage') return 'major_outage';
        }
        foreach ($components as $c) {
            if ($c['status'] === 'degraded') return 'degraded';
        }
        return 'operational';
    }
}
