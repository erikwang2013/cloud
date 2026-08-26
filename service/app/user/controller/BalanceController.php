<?php
namespace App\user\controller;

use App\user\model\UserBalance;
use App\user\model\UserBalanceLog;
use Common\helper\Response;

class BalanceController
{
    public function index($request)
    {
        $balances = UserBalance::where('user_id', $request->userId)->get();
        return json(Response::success($balances));
    }

    public function transactions($request)
    {
        $logs = UserBalanceLog::where('user_id', $request->userId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::paginated(
            $logs->items(),
            $logs->total(),
            $request->input('page', 1),
            20
        ));
    }
}
