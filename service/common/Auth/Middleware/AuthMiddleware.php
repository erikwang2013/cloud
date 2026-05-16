<?php
namespace Common\Auth\Middleware;

use Common\Auth\JwtAuth;
use Common\Helper\Response;

class AuthMiddleware
{
    public function process($request, callable $next)
    {
        $header = $request->header('Authorization');
        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return json(Response::error(401, 'Unauthorized'));
        }

        $token = substr($header, 7);
        $jwt = new JwtAuth();

        try {
            $payload = $jwt->verify($token);
        } catch (\Exception $e) {
            return json(Response::error(401, 'Invalid token'));
        }

        if (($payload['type'] ?? '') !== 'access') {
            return json(Response::error(401, 'Invalid token type'));
        }

        // Check if token is blacklisted
        try {
            if (\Redis::exists('jwt_blacklist:' . hash('sha256', $token))) {
                return json(Response::error(401, 'Token revoked'));
            }
        } catch (\Exception $e) {
            // Redis unavailable — allow through (blacklist check is best-effort)
        }

        $request->userId = $payload['sub'];
        $request->userRole = $payload['role'] ?? 'user';

        return $next($request);
    }
}

class OptionalAuthMiddleware
{
    public function process($request, callable $next)
    {
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            $jwt = new JwtAuth();
            try {
                $payload = $jwt->verify($token);
                if (($payload['type'] ?? '') === 'access') {
                    $request->userId = $payload['sub'];
                    $request->userRole = $payload['role'] ?? 'user';
                }
            } catch (\Exception $e) {}
        }
        return $next($request);
    }
}
