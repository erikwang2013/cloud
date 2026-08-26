<?php
namespace App\provisioning\controller;

use App\provisioning\model\HostMachine;
use Common\helper\Response;

class HostController
{
    public function index($request)
    {
        $hosts = HostMachine::with('region')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::success($hosts));
    }
}
