<?php
namespace App\cdn\service;

use App\provisioning\model\Resource;

interface CachePurgeInterface
{
    public function purgeCache(Resource $resource, array $urls): array;
}
