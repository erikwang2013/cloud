<?php
namespace App\Notification\Controller;

use App\Notification\Model\Notification;
use App\User\Model\User;
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

    public function preferences($request)
    {
        $user  = User::findOrFail($request->userId);
        $prefs = $user->notification_prefs ? json_decode($user->notification_prefs, true) : [
            'email' => ['order' => true, 'resource' => true, 'ticket' => true, 'promo' => false],
            'sms'   => ['order' => false, 'resource' => true, 'ticket' => false, 'promo' => false],
            'push'  => ['order' => true, 'resource' => true, 'ticket' => true, 'promo' => true],
            'in_app'=> ['order' => true, 'resource' => true, 'ticket' => true, 'promo' => false],
        ];

        return json(Response::success($prefs));
    }

    public function updatePreferences($request)
    {
        $user  = User::findOrFail($request->userId);
        $prefs = $request->input('preferences');

        if (empty($prefs) || !is_array($prefs)) {
            return json(Response::error(422, 'Invalid preferences format'));
        }

        $user->update(['notification_prefs' => json_encode($prefs)]);
        return json(Response::success($prefs, 'Notification preferences updated'));
    }
}
