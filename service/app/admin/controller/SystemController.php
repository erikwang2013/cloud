<?php
namespace App\admin\controller;

use Common\feature\FeatureFlags;
use Common\helper\Response;
use Common\security\AuditLogger;
use Illuminate\Database\Capsule\Manager as DB;

class SystemController
{
    public function auditLogs($request)
    {
        $logs = DB::connection('audit')
            ->table('audit_logs')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return json(Response::paginated($logs->items(), $logs->total(), $request->input('page', 1), 30));
    }

    public function updateConfig($request)
    {
        AuditLogger::record('admin_config_update', [
            'user_id' => $request->userId,
            'input'   => $request->all(),
        ], $request);

        return json(Response::success(null, 'Config updated'));
    }

    // ── Feature flag management ──

    public function features()
    {
        return json(Response::success(FeatureFlags::all()));
    }

    public function toggleFeature($request, string $name)
    {
        $action = $request->input('action', 'toggle');

        if (!array_key_exists($name, config('features') ?: [])) {
            return json(Response::error(404, "Unknown feature: {$name}"));
        }

        match ($action) {
            'enable'  => FeatureFlags::enable($name),
            'disable' => FeatureFlags::disable($name),
            'reset'   => FeatureFlags::reset($name),
            'toggle'  => FeatureFlags::isEnabled($name)
                ? FeatureFlags::disable($name)
                : FeatureFlags::enable($name),
            default   => null,
        };

        AuditLogger::record('admin_feature_toggle', [
            'user_id' => $request->userId,
            'input'   => ['feature' => $name, 'action' => $action],
        ], $request);

        return json(Response::success([
            'feature' => $name,
            'enabled' => FeatureFlags::isEnabled($name),
            'source'  => FeatureFlags::all()[$name]['source'] ?? 'unknown',
        ]));
    }
}
