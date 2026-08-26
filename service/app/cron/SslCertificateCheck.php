<?php
namespace App\cron;

use App\ssl\model\SslCertificate;
use App\provisioning\model\ProvisionTask;
use App\user\model\User;
use App\notification\service\NotificationDispatcher;

class SslCertificateCheck
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SslCertificateCheck: Scanning SSL certificates...\n";

        $this->checkManagedCerts();
        $this->checkExternalDomains();

        echo date('Y-m-d H:i:s') . " SslCertificateCheck: Done.\n";
    }

    private function checkManagedCerts(): void
    {
        $notifier = new NotificationDispatcher();

        $expiringCerts = SslCertificate::where('status', 'issued')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', date('Y-m-d H:i:s', strtotime('+30 days')))
            ->get();

        foreach ($expiringCerts as $cert) {
            $daysLeft = max(0, (int) ceil((strtotime($cert->expires_at) - time()) / 86400));

            if ($daysLeft <= 0) {
                $cert->update(['status' => 'expired']);
                echo "  [MANAGED] {$cert->domain_name}: EXPIRED\n";
                continue;
            }

            if ($cert->auto_renew && $daysLeft <= 14) {
                $resource = $cert->resource;
                if ($resource) {
                    ProvisionTask::create([
                        'resource_id'   => $resource->id,
                        'product_type'  => 'ssl',
                        'provider'      => $resource->provider,
                        'region_id'     => $resource->region_id ?? 0,
                        'action'        => 'renew',
                        'status'        => 'pending',
                        'params'        => json_encode([
                            'domain'       => $cert->domain_name,
                            'cert_type'    => $cert->cert_type,
                            'wildcard'     => $cert->wildcard,
                            'validation_method' => $cert->validation_method,
                        ]),
                        'next_retry_at' => date('Y-m-d H:i:s'),
                    ]);
                    echo "  [MANAGED] {$cert->domain_name}: auto-renew queued ({$daysLeft} days left)\n";
                }
            }

            $cert->update(['last_checked_at' => date('Y-m-d H:i:s')]);

            if (in_array($daysLeft, [30, 14, 7, 1])) {
                $user = User::find($resource->user_id ?? 0);
                if ($user) {
                    $notifier->dispatch($user->id, 'ssl_expiring', [
                        'domain'    => $cert->domain_name,
                        'days_left' => $daysLeft,
                        'auto_renew' => $cert->auto_renew,
                    ], ['email', 'in_app']);
                }
            }
        }
    }

    private function checkExternalDomains(): void
    {
        $notifier = new NotificationDispatcher();
        $domains = \App\domain\model\DnsZone::whereNotNull('domain_name')->get();
        $warnDays = [30, 14, 7, 1];

        foreach ($domains as $domain) {
            $managed = SslCertificate::where('domain_name', $domain->domain_name)->exists();
            if ($managed) continue;

            try {
                $context = stream_context_create(['ssl' => ['capture_peer_cert' => true]]);
                $client  = @stream_socket_client("ssl://{$domain->domain_name}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
                if (!$client) continue;

                $cert   = stream_context_get_params($client);
                $parsed = openssl_x509_parse($cert['options']['ssl']['peer_certificate'] ?? '');
                fclose($client);
                if (empty($parsed['validTo_time_t'])) continue;

                $daysLeft = (int) ceil(($parsed['validTo_time_t'] - time()) / 86400);
                if ($daysLeft <= 0) continue;

                if (in_array($daysLeft, $warnDays)) {
                    echo "  [EXTERNAL] {$domain->domain_name}: {$daysLeft} days until expiry\n";
                    $user = User::find($domain->user_id);
                    if ($user) {
                        $notifier->dispatch($user->id, 'ssl_expiring', [
                            'domain'    => $domain->domain_name,
                            'days_left' => $daysLeft,
                        ], ['email', 'in_app']);
                    }
                }
            } catch (\Throwable $e) {
                echo "  [EXTERNAL] {$domain->domain_name}: ERROR - {$e->getMessage()}\n";
            }
        }
    }
}
