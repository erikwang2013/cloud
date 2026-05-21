<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * Process bootstrap classes — executed once when each worker process starts.
 */
return [
    /** Sentry error monitoring — initializes on worker start if SENTRY_DSN is configured. */
    support\SentryBootstrap::class,
];
