<?php
namespace App\Admin\Controller;

use App\User\Model\User;
use Common\Helper\Response;

class UserController
{
    public function index($request)
    {
        $query = User::with('profile');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($users->items(), $users->total(), $request->input('page', 1), 30));
    }

    public function show(int $id)
    {
        $user = User::with(['profile', 'kyc', 'balances', 'addresses'])->findOrFail($id);
        return json(Response::success($user));
    }

    public function updateStatus($request, int $id)
    {
        $user = User::findOrFail($id);
        $user->update(['status' => $request->input('status')]);
        return json(Response::success($user));
    }
}
