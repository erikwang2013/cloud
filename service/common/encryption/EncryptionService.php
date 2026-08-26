<?php
namespace Common\encryption;

use Erikwang2013\Encryption\EncryptionManager;
use Erikwang2013\Encryption\EncryptionManagerFactory;

class EncryptionService
{
    private static ?EncryptionManager $manager = null;

    public static function init(): EncryptionManager
    {
        if (self::$manager === null) {
            $masterKey = base64_decode(getenv('ENCRYPTION_MASTER_KEY') ?: '');
            if (empty($masterKey) || strlen($masterKey) !== 32) {
                throw new \RuntimeException('ENCRYPTION_MASTER_KEY must be 32 bytes base64-encoded');
            }
            self::$manager = EncryptionManagerFactory::fromMasterKey($masterKey, 'aes-256-gcm');
        }
        return self::$manager;
    }

    public static function encrypt(string $plaintext): string
    {
        return self::init()->encrypt($plaintext);
    }

    public static function decrypt(string $ciphertext): string
    {
        return self::init()->decrypt($ciphertext);
    }

    public static function encryptField(string $plaintext): string
    {
        return base64_encode(self::encrypt($plaintext));
    }

    public static function decryptField(string $base64): string
    {
        return self::decrypt(base64_decode($base64));
    }
}
