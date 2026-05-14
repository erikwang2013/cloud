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

        if ($payload->type !== 'access') {
            return json(Response::error(401, 'Invalid token type'));
        }

        if ($jwt->isRevoked($payload->jti)) {
            return json(Response::error(401, 'Token revoked'));
        }

        $request->userId = $payload->sub;
        $request->userRole = $payload->role;

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
                if ($payload->type === 'access' && !$jwt->isRevoked($payload->jti)) {
                    $request->userId = $payload->sub;
                    $request->userRole = $payload->role;
                }
            } catch (\Exception $e) {}
        }
        return $next($request);
    }
}
