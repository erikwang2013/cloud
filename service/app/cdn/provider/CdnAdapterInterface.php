<?php
namespace App\cdn\provider;

use App\cdn\model\ResourceCdn;

interface CdnAdapterInterface
{
    public function createDomain(ResourceCdn $cdn): array;

    public function configureDomain(ResourceCdn $cdn): array;

    public function purgeCache(ResourceCdn $cdn, array $urls): array;

    public function disableDomain(ResourceCdn $cdn): array;

    public function requiresIcpRegistration(): bool;
}
