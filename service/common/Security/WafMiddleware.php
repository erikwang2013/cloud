<?php
namespace Common\Security;

use Common\Helper\Response;

class WafMiddleware
{
    public function process($request, callable $next)
    {
        $path = $request->path();

        // Request size limits (only for API routes)
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
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

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

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input) || $this->match($pattern, $url) || $this->match($pattern, $ua) || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
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
