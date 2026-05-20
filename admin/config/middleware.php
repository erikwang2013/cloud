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

use app\middleware\AccessControl;
use app\middleware\WafMiddleware;

/**
 * Global middleware — executed on every request in the order listed.
 *
 * The empty string key '' means "apply to all routes."
 *
 * WafMiddleware scans input for SQL injection, XSS, command injection,
 * file inclusion, and header injection patterns.
 * AccessControl checks session login state and RBAC permissions via plugin\admin\api\Auth.
 */
return [
    '' => [
        WafMiddleware::class,
        AccessControl::class,
    ]
];
