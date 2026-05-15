<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use Hashids\Hashids as HashidsClient;
use Webman\Config;
use Webman\Container;

// Seed a predictable salt so hashids output is deterministic.
putenv('HASHIDS_SALT=test-salt-for-phpunit');

// Build hashids config manually so env vars take effect.
$hashidsConfig = [
    'default' => 'main',
    'connections' => [
        'main' => [
            'salt' => 'test-salt-for-phpunit',
            'length' => 0,
        ],
    ],
];

// Create container and register hashids services.
$container = new Container;
$factory = new HashidsFactory;
$manager = new HashidsManager($hashidsConfig, $factory);

$container->addDefinitions([
    HashidsFactory::class => static fn (): HashidsFactory => $factory,
    HashidsManager::class => static fn (): HashidsManager => $manager,
    'hashids' => static fn (): HashidsManager => $manager,
    HashidsClient::class => static fn (): HashidsClient => $manager->connection(),
]);

// Inject config into the static Config store via reflection.
$ref = new ReflectionClass(Config::class);
$prop = $ref->getProperty('config');
$prop->setAccessible(true);
$prop->setValue(null, [
    'container' => $container,
    'hashids' => $hashidsConfig,
    'plugin.erikwang2013.hashids' => ['enable' => true],
]);
