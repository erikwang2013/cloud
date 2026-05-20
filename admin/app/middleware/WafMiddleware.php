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
        $input = json_encode($request->all());
        $url   = $request->path() . '?' . $request->queryString();
        $ua    = $request->header('User-Agent', '');

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

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input) || preg_match($pattern, $url) || preg_match($pattern, $ua)) {
                if ($request->expectsJson()) {
                    return new Response(403, ['Content-Type' => 'application/json'], json_encode(['code' => 403, 'message' => 'Request blocked by WAF']));
                }
                return new Response(403, [], 'Request blocked');
            }
        }

        return $handler($request);
    }
}
