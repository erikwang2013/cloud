<?php

return [
    \App\command\MigrateCommand::class,
    \App\command\MigrateRollbackCommand::class,
    \App\command\MigrateStatusCommand::class,
    \App\command\DbBackupCommand::class,
    \App\command\I18nSyncCommand::class,
    \App\command\ReconcileCommand::class,
];
