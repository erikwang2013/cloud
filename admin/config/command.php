<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Symfony Console command registration.
 *
 * These commands are available via `php webman <command>` from the project root.
 * All three are database migration commands:
 *
 *   php webman migrate          Run pending migrations
 *   php webman migrate:rollback  Rollback the last batch
 *   php webman migrate:status    Show migration status
 *
 * Migrations use MySQL advisory locks (GET_LOCK) for safe concurrent execution
 * and run inside transactions. See app\common\MigrationRunner for details.
 */
return [
    \app\command\MigrateCommand::class,
    \app\command\MigrateRollbackCommand::class,
    \app\command\MigrateStatusCommand::class,
];
