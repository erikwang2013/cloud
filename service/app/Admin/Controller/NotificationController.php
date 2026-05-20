<?php
namespace App\Admin\Controller;

use App\Notification\Model\NotificationTemplate;
use App\Notification\Model\Notification;
use Common\Helper\Response;

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
        $template->update($request->only(['name', 'channels', 'title_template', 'body_template', 'variables']));
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
