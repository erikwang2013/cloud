<?php
namespace App\admin\controller;

use App\notification\model\NotificationTemplate;
use App\notification\model\Notification;
use Common\helper\Response;

class NotificationController
{
    public function templates()
    {
        $templates = NotificationTemplate::orderBy('code')->get();
        return json(Response::success($templates));
    }

    public function updateTemplate($request, int $id)
    {
        $template = NotificationTemplate::findOrFail($id);
        $data     = $request->only(['name', 'channels']);

        // 按实际 schema 写入，兼容 install.sql（title/body）与迁移 0009（title_template/body_template）
        $schema = \Illuminate\Database\Capsule\Manager::schema();
        if ($schema->hasColumn('notification_templates', 'title')) {
            $data['title'] = $request->input('title');
            $data['body']  = $request->input('body');
        }
        if ($schema->hasColumn('notification_templates', 'title_template')) {
            $data['title_template'] = $request->input('title_template');
            $data['body_template']  = $request->input('body_template');
            $data['variables']      = $request->input('variables');
        }

        $template->update($data);
        return json(Response::success($template));
    }

    public function sendLog($request)
    {
        $logs = Notification::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(30);
        return json(Response::paginated($logs->items(), $logs->total(), $request->input('page', 1), 30));
    }
}
