<?php
namespace App\Cron;

class SslCertificateCheck
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SslCertificateCheck: Scanning SSL certificates...\n";

        $domains = \App\Domain\Model\DnsZone::whereNotNull('domain_name')->get();
        $warnDays = [30, 14, 7, 1];

        foreach ($domains as $domain) {
            try {
                $context = stream_context_create(['ssl' => ['capture_peer_cert' => true]]);
                $client  = @stream_socket_client("ssl://{$domain->domain_name}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

                if (!$client) continue;

                $cert   = stream_context_get_params($client);
                $parsed = openssl_x509_parse($cert['options']['ssl']['peer_certificate'] ?? '');
                fclose($client);

                if (empty($parsed['validTo_time_t'])) continue;

                $daysLeft = (int) ceil(($parsed['validTo_time_t'] - time()) / 86400);
                if ($daysLeft <= 0) {
                    echo "  {$domain->domain_name}: EXPIRED\n";
                    continue;
                }

                if (in_array($daysLeft, $warnDays)) {
                    echo "  {$domain->domain_name}: {$daysLeft} days until expiry\n";
                    $user = \App\User\Model\User::find($domain->user_id);
                    if ($user) {
                        \Common\Notification\NotificationDispatcher::send($user, 'ssl_expiring', [
                            'domain'    => $domain->domain_name,
                            'days_left' => $daysLeft,
                        ], ['email', 'in_app']);
                    }
                }
            } catch (\Throwable $e) {
                echo "  {$domain->domain_name}: ERROR - {$e->getMessage()}\n";
            }
        }

        echo date('Y-m-d H:i:s') . " SslCertificateCheck: Done.\n";
    }
}
