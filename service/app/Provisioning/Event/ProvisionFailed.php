<?php
namespace App\Provisioning\Event;

use App\Provisioning\Model\ProvisionTask;

class ProvisionFailed
{
    public ProvisionTask $task;

    public function __construct(ProvisionTask $task)
    {
        $this->task = $task;
    }
}
