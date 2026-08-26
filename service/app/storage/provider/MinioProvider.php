<?php
namespace App\storage\provider;

class MinioProvider extends S3StorageProvider
{
    protected bool $pathStyle = true;
    protected bool $verifySsl = false;
}
