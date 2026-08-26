<?php
namespace Common\auth\middleware;

use Common\auth\Rbac;
use Common\helper\Response;

class RbacMiddleware
{
    private string $permission;

    public function __construct(string $permission)
    {
        $this->permission = $permission;
    }

    public function process($request, callable $next)
    {
        $role = $request->userRole ?? 'guest';
        $rbac = new Rbac();

        if (!$rbac->hasPermission($role, $this->permission)) {
            return json(Response::error(403, 'Forbidden'));
        }

        return $next($request);
    }
}
