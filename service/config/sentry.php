<?php
return [
    // Sentry DSN — leave empty to disable
    'dsn'                  => getenv('SENTRY_DSN') ?: null,

    // Environment tag in Sentry UI
    'environment'          => getenv('APP_ENV') ?: 'production',

    // Performance tracing sample rate (0.0 ~ 1.0)
    'traces_sample_rate'   => (float)(getenv('SENTRY_TRACES_RATE') ?: 0.1),

    // Profiling sample rate
    'profiles_sample_rate' => (float)(getenv('SENTRY_PROFILES_RATE') ?: 0.05),

    // Error types to capture
    'error_types'          => E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE,

    // Release version (from git commit or env)
    'release'              => getenv('APP_VERSION') ?: trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: 'dev'),
];
