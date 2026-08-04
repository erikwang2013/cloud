<?php
namespace App\Cdn\Service;

use App\Provisioning\Model\Resource;

interface CachePurgeInterface
{
    public function purgeCache(Resource $resource, array $urls): array;
}
