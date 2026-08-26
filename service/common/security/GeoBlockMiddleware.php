<?php
namespace Common\security;

use Common\helper\Response;

class GeoBlockMiddleware
{
    public function process($request, callable $next)
    {
        $blocked = explode(',', getenv('GEO_BLOCKED_COUNTRIES') ?: '');
        if (empty($blocked)) {
            return $next($request);
        }

        $ip      = $request->getRealIp();
        $country = $this->lookupCountry($ip);

        if ($country && in_array(strtoupper($country), $blocked, true)) {
            return response(json_encode(Response::error(403, 'Access denied for your region')), 403, ['Content-Type' => 'application/json']);
        }

        return $next($request);
    }

    private function lookupCountry(string $ip): ?string
    {
        $dbPath = getenv('GEOIP_DB_PATH') ?: storage_path('geoip/GeoLite2-Country.mmdb');
        if (!file_exists($dbPath)) return null;

        try {
            if (class_exists(\GeoIp2\Database\Reader::class)) {
                $reader = new \GeoIp2\Database\Reader($dbPath);
                return $reader->country($ip)->country->isoCode;
            }
        } catch (\Throwable $e) {
            // GeoIP lookup failed, allow through
        }
        return null;
    }
}
