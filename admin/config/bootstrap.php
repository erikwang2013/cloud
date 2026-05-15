<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Process bootstrap classes — executed once when each worker process starts.
 * Use Bootstrap classes to register singletons, initialize connections, etc.
 *
 * Order matters: Session must be first so sessions are available in later bootstraps.
 */
return [
    /** HTTP session support — must be first. */
    support\bootstrap\Session::class,

    /** Registers Snowflake ID generator singleton. */
    app\bootstrap\SnowflakeBootstrap::class,

    /** Registers Encryptable encryption resolver (PHP + DB encrypters). */
    app\bootstrap\EncryptableBootstrap::class,

    /** Registers EncryptionManager singleton for API-level crypto. */
    app\bootstrap\EncryptionBootstrap::class,
];
