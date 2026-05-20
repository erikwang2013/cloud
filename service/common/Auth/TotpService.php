<?php
namespace Common\Auth;

class TotpService
{
    private const DIGITS   = 6;
    private const PERIOD   = 30;
    private const ALGO     = 'sha1';
    private const ISSUER   = 'CloudPlatform';

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return self::base32Encode($bytes);
    }

    public static function getQrUrl(string $email, string $secret): string
    {
        $label = rawurlencode(self::ISSUER . ':' . $email);
        return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode(self::ISSUER) . "&algorithm=" . self::ALGO . "&digits=" . self::DIGITS . "&period=" . self::PERIOD;
    }

    public static function verify(string $secret, string $code): bool
    {
        if (strlen($code) !== self::DIGITS) return false;

        $key = self::base32Decode($secret);
        $timeSlice = (int) floor(time() / self::PERIOD);

        // Check current and adjacent time windows (±1)
        for ($i = -1; $i <= 1; $i++) {
            if (self::totp($key, $timeSlice + $i) === $code) {
                return true;
            }
        }
        return false;
    }

    private static function totp(string $key, int $timeSlice): string
    {
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac(self::ALGO, $time, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $result .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return rtrim($result, '=');
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = rtrim(strtoupper($data), '=');
        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) throw new \InvalidArgumentException('Invalid base32 character');
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) < 8) break;
            $result .= chr(bindec($chunk));
        }
        return $result;
    }
}
