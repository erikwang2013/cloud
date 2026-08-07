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

        $body = self::buildPayload($event, $payload);
        $sig  = self::signature($body);

        foreach ($urls as $url) {
            self::sendToUrl($url, $body, $sig, $event);
        }
    }

    /**
     * 定向发送测试消息到单个已注册 webhook URL（未注册则抛异常）。
     */
    public static function dispatchTo(string $url, string $event, array $payload): bool
    {
        $registered = Redis::smembers('webhook_urls') ?: [];
        if (!in_array($url, $registered, true)) {
            throw new \InvalidArgumentException('Webhook URL is not registered');
        }
        $body = self::buildPayload($event, $payload);
        return self::sendToUrl($url, $body, self::signature($body), $event);
    }

    private static function buildPayload(string $event, array $payload): string
    {
        return json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'data'      => $payload,
        ]);
    }

    private static function signature(string $body): string
    {
        $secret = getenv('WEBHOOK_SECRET') ?: '';
        return $secret ? 'sha256=' . hash_hmac('sha256', $body, $secret) : '';
    }

    private static function sendToUrl(string $url, string $body, string $sig, string $event): bool
    {
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
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            \support\Log::error("Webhook to {$url} failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Register a webhook URL (admin operation).
     */
    public static function register(string $url): void
    {
        if (!self::isSafeWebhookUrl($url)) {
            throw new \InvalidArgumentException('Webhook URL must be http(s) and must not target private/internal addresses');
        }
        Redis::sadd('webhook_urls', $url);
    }

    /**
     * SSRF 防护：仅允许 http/https，拒绝内网/保留 IP 与带 IP 的域名。
     */
    private static function isSafeWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];
        // 域名形式需域名解析后为公网 IP；IP 字面量直接校验
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !self::isPrivateIp($host);
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
            return false;
        }
        $ip = gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return !self::isPrivateIp($ip);
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        return true;
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
