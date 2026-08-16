<?php
namespace Common\Confirmation;

use App\User\Model\User;
use Common\Helper\Response;
use Common\Security\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

class ConfirmationMiddleware
{
    private const MAX_FAILURES = 5;
    private const LOCK_TTL = 900;

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
        return $next($request);
    }

    protected function verifyPassword(int $userId, string $password): bool
    {
        $user = User::find($userId);
        return $user && Hash::check($password, $user->password_hash);
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
