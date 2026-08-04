<?php
/*
 * Feature flags configuration.
 *
 * Each flag is keyed by name. Value can be:
 *   - bool: hardcoded default
 *   - env var: overridden at deploy time
 * Redis overrides take precedence and can be toggled at runtime via admin API.
 */
return [
    'supplier_external_api'   => (bool)(getenv('FEATURE_SUPPLIER_API') ?: false),
    'websocket_push'          => (bool)(getenv('FEATURE_WEBSOCKET') ?: false),
    'maintenance_redirect'    => (bool)(getenv('FEATURE_MAINTENANCE_REDIRECT') ?: false),
    'totp_two_factor'         => (bool)(getenv('FEATURE_TOTP') ?: true),  // enabled by default
    'google_oauth'            => (bool)(getenv('FEATURE_GOOGLE_OAUTH') ?: true),
    'apple_oauth'             => (bool)(getenv('FEATURE_APPLE_OAUTH') ?: true),
    'ssl_product'             => (bool)(getenv('FEATURE_SSL_PRODUCT') ?: true),
];
