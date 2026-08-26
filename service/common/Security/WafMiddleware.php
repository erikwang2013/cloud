<?php
namespace Common\Security;

use Common\Helper\Response;

class WafMiddleware
{
    public function process($request, callable $next)
    {
        $path = $request->path();

        // Request size limits (only for API routes)
        $baseType = '';
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/admin/api/')) {
            // Content-Type validation
            $contentType = strtolower(trim($request->header('Content-Type', '')));
            $allowedTypes = config('security.request_limits.allowed_content_types', ['application/json']);
            if ($contentType !== '') {
                $baseType = str_contains($contentType, ';') ? trim(strstr($contentType, ';', true)) : $contentType;
                if (!in_array($baseType, $allowedTypes, true)) {
                    return json(Response::error(415, 'Unsupported Content-Type'));
                }
            }

            // URL length check
            $maxUrlLen = config('security.request_limits.max_url_length', 2048);
            if (strlen($request->path() . '?' . $request->queryString()) > $maxUrlLen) {
                return json(Response::error(414, 'URI too long'));
            }

            // Body size check
            $maxBodySize = config('security.request_limits.max_body_size', 10 * 1024 * 1024);
            $bodySize = (int)($request->header('Content-Length', 0));
            if ($bodySize > $maxBodySize) {
                return json(Response::error(413, 'Request body too large'));
            }
        }

        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        // 只扫 query string，不扫 URL path：业务路径含 select/insert/update/delete/alert
        // 等普通词（如 /alert/index），整路径扫描会误杀正常业务端点。
        // 路径仅用 file_inclusion（路径穿越）类模式做结构校验。
        $query = mb_substr($request->queryString(), 0, 2048);
        $path  = $request->path();
        $ua  = $request->header('User-Agent', '');
        // 仅对可能携带 body 的方法读取原始 body，GET 等请求跳过，避免全量读入
        // multipart 原始体含文件二进制：解析字段已入 $input 扫描，跳过 raw 全量读取，
        // 避免大文件上传触发误报（二进制字节匹配正则）与内存放大
        $raw = in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? ($this->shouldScanRawBody($baseType) ? $this->readRawBody() : '')
            : '';

        static $patterns = null;
        if ($patterns === null) {
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
        }

        // ponytail: 路径穿越等结构模式只查 path；SQLi/XSS 等值注入模式查 query+body+UA。
        // 若未来出现纯路径型注入（如 /api/user/../admin），再把对应模式从 file_inclusion 拆出单独查 path。
        $pathPatterns = config('security.waf.file_inclusion_patterns', []);
        foreach ($pathPatterns as $pattern) {
            if ($this->match($pattern, $path)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input) || $this->match($pattern, $query) || $this->match($pattern, $ua) || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    protected function shouldScanRawBody(string $baseType): bool
    {
        return $baseType !== 'multipart/form-data';
    }

    protected function readRawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    protected function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
