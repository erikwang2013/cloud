<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\bootstrap;

use Erikwang2013\Encryption\EncryptionManager;
use Erikwang2013\Encryption\EncryptionManagerFactory;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * Bootstrap: registers the EncryptionManager singleton in the webman container.
 */
class EncryptionBootstrap implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        $masterKey = base64_decode(env('ENCRYPTION_MASTER_KEY') ?: '', true);
        if (empty($masterKey)) {
            return;
        }

        $default = env('ENCRYPTION_DEFAULT', 'aes-256-gcm');
        $manager = EncryptionManagerFactory::fromMasterKey($masterKey, $default);

        $container = \support\Container::instance();
        $container->addDefinitions([
            EncryptionManager::class => fn() => $manager,
            'encryption' => fn() => $manager,
        ]);
    }
}
