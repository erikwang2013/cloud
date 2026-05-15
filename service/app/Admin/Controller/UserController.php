<?php
namespace App\Admin\Controller;

use App\User\Model\User;
use Common\ExcelExport;
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

    public function export($request)
    {
        $query = User::with('profile');

        if ($status = $request->input('status')) $query->where('status', $status);
        if ($role = $request->input('role')) $query->where('role', $role);
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $maxRows = 10000;
        $items = $query->orderBy('created_at', 'desc')->limit($maxRows)->get()->toArray();

        $columns = ['id', 'email', 'phone', 'role', 'status', 'created_at', 'last_login_at'];
        $labels = [
            'id' => 'ID', 'email' => '邮箱', 'phone' => '手机号',
            'role' => '角色', 'status' => '状态',
            'created_at' => '注册时间', 'last_login_at' => '最后登录',
        ];

        $path = ExcelExport::export('users', $columns, $items, $labels);
        return response()->download($path, 'users_' . date('YmdHis') . '.xlsx');
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
