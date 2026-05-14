<?php
namespace App\Provisioning\Controller;

use App\Provisioning\Model\HostMachine;
use Common\Helper\Response;

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
