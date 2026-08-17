<?php
namespace Common\Auth\Middleware;

use Common\Helper\Response;

class AdminRoleMiddleware
{
    private const ALLOWED_ROLES = ['admin', 'super_admin', 'support', 'finance'];

    public function process($request, callable $next)
    {
        $role = $request->userRole ?? 'user';

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            return json(Response::error(403, 'Forbidden: admin access required'));
        }

        return $next($request);
    }
}
