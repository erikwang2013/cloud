<?php
namespace Common\Security;

class SecurityHeadersMiddleware
{
    public function process($request, callable $next)
    {
        $response = $next($request);

        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', getenv('SECURITY_X_FRAME_OPTIONS') ?: 'SAMEORIGIN');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('X-XSS-Protection', '1; mode=block');
        $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (getenv('SECURITY_HSTS_ENABLE') === 'true') {
            $response->header(
                'Strict-Transport-Security',
                getenv('SECURITY_HSTS_VALUE') ?: 'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
