<?php
namespace Common\Security;

class LogSanitizer
{
    private static array $sensitiveFields = [
        'password', 'password_hash', 'password_confirmation',
        'secret', 'api_key', 'api_secret', 'api_token',
        'token', 'access_token', 'refresh_token',
        'credit_card', 'cvv', 'card_number',
        'ssn', 'id_number', 'real_name',
        'login_password', 'private_key',
        'auth_code', 'answer',
    ];

    public static function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::isSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = self::sanitize($value);
            }
        }
        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::$sensitiveFields as $field) {
            if (str_contains($lower, $field)) {
                return true;
            }
        }
        return false;
    }
}
