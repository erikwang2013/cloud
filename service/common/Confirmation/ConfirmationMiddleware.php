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
        } catch (\Exception $e) {}

        $password = $request->input('confirm_password', '');
        if (empty($password)) {
            return json(Response::error(422, 'Password confirmation required for this operation'));
        }

        if (!$this->verifyPassword($userId, $password)) {
            $this->recordFailure($userId, $lockKey);
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

    private function recordFailure(int $userId, string $lockKey): void
    {
        try {
            $key = "confirm_failed:{$userId}";
            $count = Redis::incr($key);
            Redis::expire($key, self::LOCK_TTL);

            if ($count >= self::MAX_FAILURES) {
                Redis::setex($lockKey, self::LOCK_TTL, '1');
            }
        } catch (\Exception $e) {}
    }
}
