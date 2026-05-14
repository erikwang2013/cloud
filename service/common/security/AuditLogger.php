<?php
namespace Common\Security;

use Illuminate\Database\Capsule\Manager as DB;

class AuditLogger
{
    public static function record(string $action, array $context = [], $request = null): void
    {
        try {
            DB::connection('audit')->table('audit_logs')->insert([
                'user_id'    => $context['user_id'] ?? 0,
                'ip'         => $request ? $request->getRealIp() : '',
                'method'     => $request ? $request->method() : '',
                'path'       => $request ? $request->path() : '',
                'action'     => $action,
                'input'      => isset($context['input']) ? json_encode(LogSanitizer::sanitize((array)$context['input'])) : '{}',
                'status'     => $context['status'] ?? 'success',
                'request_id' => request_id(),
                'user_agent' => $request ? $request->header('User-Agent', '') : '',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Don't let audit failure break the request
        }
    }

    public static function unauthorized($userId, string $permission, $request): void
    {
        self::record('unauthorized', [
            'user_id' => $userId,
            'input'   => ['permission' => $permission],
            'status'  => 'blocked',
        ], $request);
    }

    public static function threat(string $type, $request): void
    {
        self::record("threat_{$type}", [
            'user_id' => 0,
            'input'   => $request->all(),
            'status'  => 'blocked',
        ], $request);
    }
}
