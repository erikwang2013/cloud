<?php
namespace Common\Auth\Middleware;

use Common\Helper\Response;

class RoleMiddleware
{
    private array $allowedRoles;

    public function __construct(string ...$roles)
    {
        $this->allowedRoles = $roles ?: ['admin', 'super_admin'];
    }

    public function process($request, callable $next)
    {
        $role = $request->userRole ?? 'user';

        if (!in_array($role, $this->allowedRoles, true)) {
            return json(Response::error(403, 'Forbidden: insufficient permissions'));
        }

        return $next($request);
    }
}
