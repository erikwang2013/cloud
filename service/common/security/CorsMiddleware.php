<?php
namespace Common\Security;

class CorsMiddleware
{
    public function process($request, callable $next)
    {
        $origin = $request->header('Origin');

        if ($request->method() === 'OPTIONS') {
            return response('', 204, [
                'Access-Control-Allow-Origin'  => $origin ?: '*',
                'Access-Control-Allow-Methods' => 'GET,POST,PUT,DELETE,OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type,Authorization,Accept-Language',
                'Access-Control-Max-Age'       => '86400',
            ]);
        }

        $response = $next($request);
        if ($origin) {
            $response->header('Access-Control-Allow-Origin', $origin);
        }
        return $response;
    }
}
