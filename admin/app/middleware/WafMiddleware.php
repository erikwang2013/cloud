<?php
/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class WafMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // Request size limits
        $contentType = strtolower(trim($request->header('Content-Type', '')));
        $allowedTypes = config('security.request_limits.allowed_content_types', ['application/json']);
        if ($contentType !== '') {
            $baseType = str_contains($contentType, ';') ? trim(strstr($contentType, ';', true)) : $contentType;
            if (!in_array($baseType, $allowedTypes, true)) {
                return new Response(415, ['Content-Type' => 'application/json'], json_encode(['code' => 415, 'message' => 'Unsupported Content-Type']));
            }
        }

        $maxUrlLen = config('security.request_limits.max_url_length', 2048);
        if (strlen($request->path() . '?' . $request->queryString()) > $maxUrlLen) {
            return new Response(414, ['Content-Type' => 'application/json'], json_encode(['code' => 414, 'message' => 'URI too long']));
        }

        $maxBodySize = config('security.request_limits.max_body_size', 10 * 1024 * 1024);
        $bodySize = (int)($request->header('Content-Length', 0));
        if ($bodySize > $maxBodySize) {
            return new Response(413, ['Content-Type' => 'application/json'], json_encode(['code' => 413, 'message' => 'Request body too large']));
        }

        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        // 只扫 query string，不扫 URL path：业务路径含 select/insert/update/delete/alert
        // 等普通词（如 /order_item/select），整路径扫描会误杀全部 CRUD 端点。
        // 路径仅用 file_inclusion（路径穿越）类模式做结构校验。
        $query = mb_substr($request->queryString(), 0, 2048);
        $path  = $request->path();
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        $patternGroups = [
            'security.waf.sqli_patterns',
            'security.waf.xss_patterns',
            'security.waf.cmd_injection_patterns',
            'security.waf.file_inclusion_patterns',
            'security.waf.header_injection_patterns',
            'security.waf.ssrf_patterns',
            'security.waf.nosql_injection_patterns',
            'security.waf.open_redirect_patterns',
        ];

        $patterns = [];
        foreach ($patternGroups as $group) {
            $groupPatterns = config($group);
            if (is_array($groupPatterns)) {
                $patterns = array_merge($patterns, $groupPatterns);
            }
        }
        $patterns = array_unique($patterns);

        // ponytail: 路径穿越等结构模式只查 path；SQLi/XSS 等值注入模式查 query+body+UA。
        // 若未来出现纯路径型注入（如 /api/user/../admin），再把对应模式从 file_inclusion 拆出单独查 path。
        $pathPatterns = config('security.waf.file_inclusion_patterns', []);
        foreach ($pathPatterns as $pattern) {
            if ($this->match($pattern, $path)) {
                error_log(sprintf(
                    'WAF blocked [%s] %s from %s',
                    $request->method(),
                    $request->path(),
                    $request->getRealIp()
                ));
                if ($request->expectsJson()) {
                    return new Response(403, ['Content-Type' => 'application/json'], json_encode(['code' => 403, 'message' => 'Request blocked by WAF']));
                }
                return new Response(403, [], 'Request blocked');
            }
        }

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input) || $this->match($pattern, $query) || $this->match($pattern, $ua) || $this->match($pattern, $raw)) {
                error_log(sprintf(
                    'WAF blocked [%s] %s from %s',
                    $request->method(),
                    $request->path(),
                    $request->getRealIp()
                ));
                if ($request->expectsJson()) {
                    return new Response(403, ['Content-Type' => 'application/json'], json_encode(['code' => 403, 'message' => 'Request blocked by WAF']));
                }
                return new Response(403, [], 'Request blocked');
            }
        }

        return $handler($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
