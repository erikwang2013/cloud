<?php
namespace Common\Webhook;

use Illuminate\Support\Facades\Redis;

class WebhookDispatcher
{
    /**
     * Dispatch an event to all registered webhook URLs.
     */
    public static function dispatch(string $event, array $payload): void
    {
        $urls = Redis::smembers('webhook_urls') ?: [];
        if (empty($urls)) return;

        $body = json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'data'      => $payload,
        ]);

        $secret = getenv('WEBHOOK_SECRET') ?: '';
        $sig    = $secret ? 'sha256=' . hash_hmac('sha256', $body, $secret) : '';

        foreach ($urls as $url) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'X-Webhook-Signature: ' . $sig,
                        'X-Webhook-Event: ' . $event,
                    ],
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Log delivery status
                if ($statusCode < 200 || $statusCode >= 300) {
                    \support\Log::warning("Webhook to {$url} returned {$statusCode} for event {$event}");
                }
            } catch (\Throwable $e) {
                \support\Log::error("Webhook to {$url} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Register a webhook URL (admin operation).
     */
    public static function register(string $url): void
    {
        Redis::sadd('webhook_urls', $url);
    }

    /**
     * Unregister a webhook URL.
     */
    public static function unregister(string $url): void
    {
        Redis::srem('webhook_urls', $url);
    }

    /**
     * List all registered webhook URLs.
     */
    public static function list(): array
    {
        return Redis::smembers('webhook_urls') ?: [];
    }
}
