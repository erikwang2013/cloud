<?php
namespace Common\confirmation;

use App\user\model\User;
use Common\helper\Response;
use Common\security\AuditLogger;

use Illuminate\Support\Facades\Redis;

class ConfirmationMiddleware
{
    private const MAX_FAILURES = 5;
    private const LOCK_TTL = 900;

    public function __construct(private bool $requireApprover = false) {}

    public function process($request, callable $next)
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(Response::error(401, 'Authentication required'));
        }

        $lockKey = "confirm_lock:{$userId}";
        try {
            if (Redis::exists($lockKey)) {
                return json(Response::error(429, 'Too many confirmation attempts, try again later'));
            }
        } catch (\Exception $e) {
            // Redis 不可用时 fail-closed：资金/破坏性操作不得绕过锁定计数
            return json(Response::error(503, 'Confirmation service temporarily unavailable'));
        }

        $password = $request->input('confirm_password', '');
        if (empty($password)) {
            return json(Response::error(422, 'Password confirmation required for this operation'));
        }

        if (!$this->verifyPassword($userId, $password)) {
            if (!$this->recordFailure($userId, $lockKey)) {
                return json(Response::error(503, 'Confirmation service temporarily unavailable'));
            }
            AuditLogger::record('confirm_failed', ['user_id' => $userId], $request);
            return json(Response::error(403, 'Password verification failed'));
        }

        // Clear failure count on success
        try {
            Redis::del("confirm_failed:{$userId}");
        } catch (\Exception $e) {}

        AuditLogger::record('confirm_success', ['user_id' => $userId], $request);

        if ($this->requireApprover) {
            $approved = $this->verifyApprover($request, $userId);
            if ($approved !== true) {
                return $approved;
            }
        }

        return $next($request);
    }

    /**
     * 第二人审批：approver 身份由 body 中的 approver_id + approver_password 证明，
     * 角色必须查 DB（本请求中 approver 无 token，不信任任何请求体/JWT 角色声明）。
     * approver 限定 admin/super_admin，防止 finance 等低权角色自批自审。
     */
    private function verifyApprover($request, int $userId)
    {
        $approverId = (int) $request->input('approver_id', 0);
        $approverPassword = $request->input('approver_password', '');
        if ($approverId <= 0 || $approverId === $userId) {
            return json(Response::error(422, 'Approver required and must differ from operator'));
        }
        if (empty($approverPassword)) {
            return json(Response::error(422, 'Approver password confirmation required for this operation'));
        }

        $approver = $this->findApprover($approverId);
        if (!$approver || !in_array($approver->role, ['admin', 'super_admin'], true)) {
            return json(Response::error(403, 'Approver not authorized'));
        }

        $approverLockKey = "confirm_lock:{$approverId}";
        try {
            if (Redis::exists($approverLockKey)) {
                return json(Response::error(429, 'Too many confirmation attempts, try again later'));
            }
        } catch (\Exception $e) {
            return json(Response::error(503, 'Confirmation service temporarily unavailable'));
        }

        if (!$this->verifyApproverPassword($approver, $approverPassword)) {
            // 失败计入 approver 自己的锁定计数，防止对任意 admin 账号无限爆破
            if (!$this->recordFailure($approverId, $approverLockKey)) {
                return json(Response::error(503, 'Confirmation service temporarily unavailable'));
            }
            AuditLogger::record('confirm_approve_failed', ['user_id' => $userId, 'approver_id' => $approverId], $request);
            return json(Response::error(403, 'Approver password verification failed'));
        }

        try {
            Redis::del("confirm_failed:{$approverId}");
        } catch (\Exception $e) {}

        AuditLogger::record('confirm_approved', ['user_id' => $userId, 'approver_id' => $approverId], $request);
        return true;
    }

    protected function findApprover(int $approverId): ?User
    {
        return User::find($approverId);
    }

    protected function verifyApproverPassword(User $approver, string $password): bool
    {
        return password_verify($password, $approver->password_hash);
    }

    protected function verifyPassword(int $userId, string $password): bool
    {
        $user = User::find($userId);
        return $user && password_verify($password, $user->password_hash);
    }

    private function recordFailure(int $userId, string $lockKey): bool
    {
        try {
            $key = "confirm_failed:{$userId}";
            $count = Redis::incr($key);
            Redis::expire($key, self::LOCK_TTL);

            if ($count >= self::MAX_FAILURES) {
                Redis::setex($lockKey, self::LOCK_TTL, '1');
            }
            return true;
        } catch (\Exception $e) {
            // 计数无法落盘时不得放行（fail-closed），由调用方返回 503
            return false;
        }
    }
}
