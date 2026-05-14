<?php
namespace App\Provisioning\Model;

class ProvisionResult
{
    public string $status; // success/retryable/failed
    public array $data;
    public ?string $errorMessage;

    public static function success(array $data = []): self
    {
        $r = new self();
        $r->status = 'success';
        $r->data = $data;
        return $r;
    }

    public static function retryable(string $error): self
    {
        $r = new self();
        $r->status = 'retryable';
        $r->errorMessage = $error;
        return $r;
    }

    public static function failed(string $error): self
    {
        $r = new self();
        $r->status = 'failed';
        $r->errorMessage = $error;
        return $r;
    }
}

class ResourceStatus
{
    public string $status;    // running/stopped/pending/error
    public array $metrics;   // cpu_percent, mem_percent, disk_percent, bw_usage
}
