<?php
namespace App\User\Controller;

use App\User\Model\UserBalance;
use Common\Helper\Response;

class BalanceController
{
    public function index($request)
    {
        $balances = UserBalance::where('user_id', $request->userId)->get();
        return json(Response::success($balances));
    }
}
