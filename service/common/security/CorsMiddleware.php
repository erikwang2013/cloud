<?php
namespace Common\security;

class CorsMiddleware
{
    public function process($request, callable $next)
    {
        $origin = $request->header('Origin');

        if ($request->method() === 'OPTIONS') {
            $allowedOrigin = $origin && $this->isAllowed($origin) ? $origin : 'null';
            return response('', 204, [
                'Access-Control-Allow-Origin'  => $allowedOrigin,
                'Access-Control-Allow-Methods' => 'GET,POST,PUT,DELETE,OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type,Authorization,Accept-Language',
                'Access-Control-Max-Age'       => '86400',
            ]);
        }

        $response = $next($request);
        if ($origin && $this->isAllowed($origin)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
        }
        return $response;
    }

    private function isAllowed(string $origin): bool
    {
        $allowed = $this->getAllowedOrigins();
        if (empty($allowed)) {
            return false;
        }
        foreach ($allowed as $pattern) {
            if ($this->originMatches($origin, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function getAllowedOrigins(): array
    {
        $raw = getenv('CORS_ALLOWED_ORIGINS') ?: '';
        if ($raw === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    private function originMatches(string $origin, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }
        if (str_starts_with($pattern, '*.')) {
            $domain = substr($pattern, 2);
            return str_ends_with($origin, '.' . $domain)
                || $origin === $domain;
        }
        return $origin === $pattern;
    }
}
