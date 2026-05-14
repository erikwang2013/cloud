<?php
namespace Common\Security;

class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all());
        $sqliPatterns = config('security.waf.sqli_patterns');
        $xssPatterns  = config('security.waf.xss_patterns');
        $patterns = array_merge($sqliPatterns, $xssPatterns);
        $patterns[] = '/\.\.\/|\.\.\%2f|\.\.\\\\/i';

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(\Common\Helper\Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }
}
