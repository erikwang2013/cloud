<?php
namespace App\Admin\Controller;

use Common\Helper\Response;
use Illuminate\Database\Capsule\Manager as DB;

class SystemController
{
    public function auditLogs($request)
    {
        // In production: query audit database connection
        $logs = DB::connection('audit')
            ->table('audit_logs')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return json(Response::paginated($logs->items(), $logs->total(), $request->input('page', 1), 30));
    }

    public function updateConfig($request)
    {
        // In production: write to system config table
        return json(Response::success(null, 'Config updated'));
    }
}
