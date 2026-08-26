<?php
namespace App\provisioning\controller;

use App\provisioning\model\ProvisionTask;
use Common\helper\Response;

class TaskController
{
    public function index($request)
    {
        $tasks = ProvisionTask::with('orderItem')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::success($tasks));
    }

    public function retry($request, int $id)
    {
        $task = ProvisionTask::findOrFail($id);
        $task->update([
            'status'        => 'pending',
            'retry_count'   => 0,
            'last_error'    => null,
            'next_retry_at' => date('Y-m-d H:i:s'),
        ]);

        return json(Response::success($task));
    }
}
