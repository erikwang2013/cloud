<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Hashids webman bootstrap registration.
 *
 * Registers the HashidsManager, HashidsFactory, and the default Hashids\Hashids
 * connection as singletons in the webman container on each process start.
 */
return [
    Erikwang2013\Hashids\Webman\Bootstrap::class,
];
