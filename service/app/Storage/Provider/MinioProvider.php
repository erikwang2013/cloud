<?php
namespace App\Storage\Provider;

class MinioProvider extends S3StorageProvider
{
    protected bool $pathStyle = true;
    protected bool $verifySsl = false;
}
