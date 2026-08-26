<?php
namespace Common\auth\middleware;

use Common\auth\JwtAuth;
use Common\helper\Response;

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

        // 黑名单由 jwt-webman 库在 decode() 内按 jti 校验，无需在此重复检查

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
