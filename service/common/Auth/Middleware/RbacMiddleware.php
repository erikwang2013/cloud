<?php
namespace Common\Auth\Middleware;

use Common\Auth\Rbac;
use Common\Helper\Response;

class RbacMiddleware
{
    public function process($request, callable $next, string $permission)
    {
        $role = $request->userRole ?? 'guest';
        $rbac = new Rbac();

        if (!$rbac->hasPermission($role, $permission)) {
            return json(Response::error(403, 'Forbidden'));
        }

        return $next($request);
    }
}
