<?php
namespace Common\security;

use Common\feature\FeatureFlags;

class MaintenanceMiddleware
{
    public function process($request, callable $next)
    {
        $maintenance = getenv('MAINTENANCE_MODE') === 'true'
            || FeatureFlags::isEnabled('maintenance_redirect');

        if (!$maintenance) {
            return $next($request);
        }

        $allowedIps = explode(',', getenv('MAINTENANCE_ALLOWED_IPS') ?: '');
        if (in_array($request->getRealIp(), $allowedIps, true)) {
            return $next($request);
        }

        return response(json_encode([
            'code'    => 503,
            'message' => 'Service temporarily unavailable. Maintenance in progress.',
        ]), 503, ['Content-Type' => 'application/json', 'Retry-After' => '3600']);
    }
}
