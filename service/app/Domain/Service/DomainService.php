<?php
namespace App\Domain\Service;

use App\Domain\Model\DomainTld;
use App\Domain\Model\DnsZone;
use App\Domain\Model\DnsRecord;
use App\Provisioning\Model\ProvisionTask;

class DomainService
{
    public function checkAvailability(string $domainName, string $tld): array
    {
        $tldConfig = DomainTld::where('tld', $tld)->firstOrFail();

        return [
            'domain'    => $domainName,
            'tld'       => $tld,
            'available' => true,
            'price'     => [
                'register' => $tldConfig->retail_price,
                'renew'    => $tldConfig->retail_price,
                'transfer' => $tldConfig->retail_price,
            ],
            'promo_price'     => $tldConfig->promo_price,
            'promo_price_end' => $tldConfig->promo_end_at,
        ];
    }

    public function register(int $userId, string $domainName, string $tld, array $options = []): void
    {
        $tldConfig = DomainTld::where('tld', $tld)->firstOrFail();

        ProvisionTask::create([
            'order_id'      => $options['order_id'] ?? 0,
            'order_item_id' => $options['order_item_id'] ?? 0,
            'product_type'  => 'domain',
            'provider'      => $tldConfig->registrar,
            'action'        => 'register',
            'status'        => 'pending',
            'params'        => json_encode([
                'domain'        => $domainName . '.' . $tld,
                'years'         => $options['years'] ?? 1,
                'whois_privacy' => $options['whois_privacy'] ?? true,
                'nameservers'   => $options['nameservers'] ?? [],
            ]),
            'next_retry_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function addDnsRecord(int $userId, string $domainName, array $data): DnsRecord
    {
        $zone = DnsZone::where('domain_name', $domainName)
            ->where('user_id', $userId)
            ->firstOrFail();

        return DnsRecord::create([
            'zone_id'  => $zone->id,
            'type'     => $data['type'],
            'name'     => $data['name'],
            'value'    => $data['value'],
            'ttl'      => $data['ttl'] ?? 600,
            'priority' => $data['priority'] ?? null,
        ]);
    }

    public function listDnsRecords(int $userId, string $domainName): array
    {
        $zone = DnsZone::where('domain_name', $domainName)
            ->where('user_id', $userId)
            ->firstOrFail();

        return DnsRecord::where('zone_id', $zone->id)->get()->toArray();
    }

    public function deleteDnsRecord(int $userId, string $domainName, int $recordId): void
    {
        $zone = DnsZone::where('domain_name', $domainName)
            ->where('user_id', $userId)
            ->firstOrFail();

        DnsRecord::where('id', $recordId)->where('zone_id', $zone->id)->delete();
    }

    public function getTlds(): array
    {
        return DomainTld::where('status', 'active')->get()->toArray();
    }
}
