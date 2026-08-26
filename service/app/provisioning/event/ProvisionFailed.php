<?php
namespace App\provisioning\event;

use App\provisioning\model\ProvisionTask;

class ProvisionFailed
{
    public ProvisionTask $task;

    public function __construct(ProvisionTask $task)
    {
        $this->task = $task;
    }
}
