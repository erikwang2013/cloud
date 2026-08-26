<?php
namespace App\monitor\controller;

use App\provisioning\model\Resource;
use App\provisioning\model\ProvisionTask;
use App\monitor\model\Alert;
use Illuminate\Support\Facades\Redis;
use Common\helper\Response;

class MonitorController
{
    public function dashboard()
    {
        return json(Response::success([
            'total_resources'     => Resource::count(),
            'active_resources'    => Resource::where('status', 'active')->count(),
            'resources_by_type'   => Resource::selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type'),
            'resources_by_region' => Resource::selectRaw('region_id, count(*) as count')->where('status', 'active')->groupBy('region_id')->with('region')->get(),
            'recent_alerts'       => Alert::orderBy('created_at', 'desc')->take(20)->get(),
            'provisioning_queue'  => ProvisionTask::where('status', 'pending')->count(),
        ]));
    }

    public function resourceMetrics($request, int $id)
    {
        $metrics = [
            'cpu'    => Redis::hget("resource:{$id}:metrics", 'cpu') ?? 0,
            'memory' => Redis::hget("resource:{$id}:metrics", 'mem') ?? 0,
            'disk'   => Redis::hget("resource:{$id}:metrics", 'disk') ?? 0,
            'status' => Redis::hget("resource:{$id}:status", 'status') ?? 'unknown',
        ];
        return json(Response::success($metrics));
    }
}
