<?php
namespace App\Provisioning\Controller;

use App\Provisioning\Model\Resource;
use Common\Helper\Response;

class BatchController
{
    public function batchAction($request)
    {
        $ids    = $request->input('ids', []);
        $action = $request->input('action');

        if (empty($ids) || !is_array($ids)) {
            return json(Response::error(422, 'Resource IDs required'));
        }

        $allowed = ['reboot', 'shutdown', 'start', 'renew'];
        if (!in_array($action, $allowed, true)) {
            return json(Response::error(422, 'Invalid action. Allowed: ' . implode(', ', $allowed)));
        }

        $resources = Resource::where('user_id', $request->userId)->whereIn('id', $ids)->get();
        $results   = [];

        foreach ($resources as $resource) {
            try {
                // Dispatch to provisioning service
                $results[] = ['id' => $resource->id, 'status' => 'ok', 'action' => $action];
            } catch (\Throwable $e) {
                $results[] = ['id' => $resource->id, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return json(Response::success($results));
    }
}
