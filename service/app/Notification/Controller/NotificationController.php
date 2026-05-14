<?php
namespace App\Notification\Controller;

use App\Notification\Model\Notification;
use Common\Helper\Response;

class NotificationController
{
    public function index($request)
    {
        $notifications = Notification::where('user_id', $request->userId)
            ->where('channel', 'in_app')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::paginated(
            $notifications->items(),
            $notifications->total(),
            $request->input('page', 1),
            20
        ));
    }

    public function markRead($request, int $id)
    {
        Notification::where('id', $id)
            ->where('user_id', $request->userId)
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return json(Response::success());
    }
}
