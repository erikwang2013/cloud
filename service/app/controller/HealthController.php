<?php
namespace App\Controller;

use Common\Helper\Response;

class HealthController
{
    public function index()
    {
        return json(Response::success([
            'status'    => 'healthy',
            'timestamp' => date('c'),
            'version'   => '1.0.0',
        ]));
    }
}
