<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\bootstrap;

use Erikwang2013\Encryptable\Bridge\Laravel\IlluminateDbDriverDetector;
use Erikwang2013\Encryptable\Bridge\Webman\WebmanPluginEncryptableConfig;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\Encryptable\DBEncrypter;
use Erikwang2013\Encryptable\PHPEncrypter;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * Bootstrap: registers the Encryptable encryption resolver for PHP and DB encrypters.
 */
class EncryptableBootstrap implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        $config = new WebmanPluginEncryptableConfig();

        Encryption::setResolver(function (string $abstract) use ($config) {
            return match ($abstract) {
                PHPEncrypter::class => new PHPEncrypter($config),
                DBEncrypter::class => new DBEncrypter($config, new IlluminateDbDriverDetector(config('database.default', 'mysql'))),
                default => throw new \RuntimeException("Unknown encryptable abstract: {$abstract}"),
            };
        });
    }
}
