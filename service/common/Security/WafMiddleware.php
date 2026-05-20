<?php
namespace Common\Security;

use Common\Helper\Response;

class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        $patternGroups = [
            'security.waf.sqli_patterns',
            'security.waf.xss_patterns',
            'security.waf.cmd_injection_patterns',
            'security.waf.file_inclusion_patterns',
            'security.waf.header_injection_patterns',
        ];

        $patterns = [];
        foreach ($patternGroups as $group) {
            $groupPatterns = config($group);
            if (is_array($groupPatterns)) {
                $patterns = array_merge($patterns, $groupPatterns);
            }
        }
        $patterns = array_unique($patterns);

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
