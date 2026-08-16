<?php

namespace Tests\Security;

use Common\Security\WafMiddleware;

/**
 * WAF 测试共享支持：请求工厂 + 可测中间件子类（注入模式 + raw body seam）。
 * 独立文件使各测试类保持在 500 行内。
 */
class WafTestSupport
{
    public static function createRequest(string $path = '/api/products', array $data = [], string $ua = 'Mozilla/5.0', string $queryString = '', string $contentType = 'application/json')
    {
        return new class($path, $data, $ua, $queryString, $contentType) {
            private string $path;
            private array  $data;
            private string $ua;
            private string $qs;
            private string $ct;
            public function __construct(string $path, array $data, string $ua, string $qs, string $ct) {
                $this->path = $path;
                $this->data = $data;
                $this->ua  = $ua;
                $this->qs  = $qs;
                $this->ct  = $ct;
            }
            public function path(): string { return $this->path; }
            public function queryString(): string { return $this->qs; }
            public function all(): array { return $this->data; }
            public function header(string $name, $default = null) {
                if (strtolower($name) === 'user-agent') return $this->ua;
                if (strtolower($name) === 'content-type') return $this->ct;
                return $default;
            }
        };
    }
}

/**
 * Testable subclass that injects WAF patterns directly instead of using config().
 * This avoids the "Webman\Config cannot be loaded in test context" issue.
 */
class TestableWafMiddleware extends WafMiddleware
{
    private array $testPatterns;
    private string $rawBody = '';

    public function setRawBody(string $raw): void
    {
        $this->rawBody = $raw;
    }

    protected function readRawBody(): string
    {
        return $this->rawBody;
    }

    public function __construct()
    {
        $this->testPatterns = [
            // SQL injection
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/\b(union)\s+(all\s+)?select\b/i',
            '/\bselect\b.{0,60}\bfrom\b/i',
            '/\b(insert\s+into|delete\s+from|drop\s+table|truncate\s+table|update\s+\w+\s+set)\b/i',
            '/\b0x[0-9a-fA-F]{4,}\b/',
            '/(\%55\%4e\%49\%4f\%4e|union).*(select)/si',
            '/([\'\"\%])\s*or\s*[\'\"\%]?\s*[0-9a-z]+\s*[\'\"\%]?\s*=\s*[\'\"\%]?\s*[0-9a-z]+/i',
            '/\b(sleep|benchmark|pg_sleep)\s*\(/i',
            '/;\s*\b(drop|insert|update|delete|select|exec)\b/i',
            // XSS
            '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i',
            '/<\s*s\s*c\s*r\s*i\s*p\s*t[\s>\/]/i',
            '/<script[\s>\/]/i',
            '/\b(onload|onerror|onclick|onmouseover|onmouseout|onfocus|onblur|onkeypress|onkeydown|onkeyup|onsubmit|onchange|oninput|ondblclick|oncontextmenu|onanimationend)\b/i',
            '/\b(document\.|window\.|alert|eval|setTimeout|setInterval|Function\(|constructor)\b/i',
            '/javascript\s*:/i',
            '/&#x?[0-9a-fA-F]+/i',
            '/data\s*:\s*text\/html/i',
            '/\bon[a-z]+\s*=\s*[\"\'][^\"\']*\([^\"\']*\)/i',
            // Command injection
            '/\|\s*\b(cat|ls|rm|wget|curl|nc|bash|sh|cmd|powershell|whoami|id)\b/i',
            '/;\s*\b(cat|ls|rm|wget|curl|nc|bash|sh|cmd|powershell|whoami|id|uname|ifconfig|ipconfig|nslookup|ping)\b/i',
            '/\$\([^)]+\)/',
            '/`[^`]+`/',
            '/\b(cat|ls|rm|wget|curl|nc|netcat|bash|sh|zsh|cmd|powershell|whoami|id|uname|ifconfig|ipconfig|nslookup|ping|tracert)\s+/i',
            // File inclusion
            '/\.\.\/|\.\.\%2f|\.\.\\\\|\.\.\%5c|\.\.\/\.\.\//i',
            '/\b(php|file|glob|data|expect|phar|zip|ogg):\/\//i',
            '/(\/etc\/|\/proc\/|\/var\/|\/tmp\/|C:\\\\|%SYSTEMROOT%)/i',
            '/\%00|\\x00/',
            // Header injection
            '/\%0[ad]|\\r\\n|\\r|\\n/i',
            '/\n\s*(Host|Cookie|Set-Cookie|Location|Content-Type):/i',
            // SSRF
            '/\b(127\.\d{1,3}\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/',
            '/\b172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}\b/',
            '/\b192\.168\.\d{1,3}\.\d{1,3}\b/',
            '/\b(localhost|0\.0\.0\.0|0x7f000001)\b/i',
            '/\b169\.254\.169\.254\b/',
            '/\bfile:\/\/\/?\b/i',
            // NoSQL injection
            '/(\$where|\$gt|\$gte|\$lt|\$lte|\$ne|\$nin|\$in|\$regex|\$exists|\$or|\$and|\$nor|\$not|\$eq)\b/i',
            '/\$where\s*[=:]\s*[\"\'{]?\s*\$?(function|eval|while|for|require)/i',
            '/\b(FLUSHALL|FLUSHDB|CONFIG\s+SET|CONFIG\s+REWRITE|SHUTDOWN|DEBUG\s+SEGFAULT)\b/i',
            // Open redirect
            '/(redirect_uri|redirect_url|return_url|return_to|next|callback)["\']?\s*[=:]\s*["\']?\s*https?:\/\/(?![\w\-\.]*example\.com)/i',
        ];
    }

    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url   = $request->path() . '?' . $request->queryString();
        $ua    = $request->header('User-Agent', '');
        // 与真实 WafMiddleware 保持一致：multipart 跳过 raw 扫描
        $contentType = strtolower((string)$request->header('Content-Type', ''));
        $baseType    = str_contains($contentType, ';') ? trim(strstr($contentType, ';', true)) : $contentType;
        $raw = $this->shouldScanRawBody($baseType) ? $this->readRawBody() : '';

        foreach ($this->testPatterns as $pattern) {
            if ($this->match($pattern, $input) || $this->match($pattern, $url) || $this->match($pattern, $ua) || $this->match($pattern, $raw)) {
                return response(json_encode(['code' => 403, 'message' => 'Request blocked by WAF']), 403, ['Content-Type' => 'application/json']);
            }
        }
        return $next($request);
    }
}
