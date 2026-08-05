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
    'facebook_oauth'          => (bool)(getenv('FEATURE_FACEBOOK_OAUTH') ?: true),
    'x_oauth'                 => (bool)(getenv('FEATURE_X_OAUTH') ?: true),
    'microsoft_oauth'         => (bool)(getenv('FEATURE_MICROSOFT_OAUTH') ?: true),
    'linkedin_oauth'          => (bool)(getenv('FEATURE_LINKEDIN_OAUTH') ?: true),
    'github_oauth'            => (bool)(getenv('FEATURE_GITHUB_OAUTH') ?: true),
    'ssl_product'             => (bool)(getenv('FEATURE_SSL_PRODUCT') ?: true),
    'object_storage_product'  => (bool)(getenv('FEATURE_OBJECT_STORAGE') ?: true),
    'usage_billing'           => (bool)(getenv('FEATURE_USAGE_BILLING') ?: true),
    'prometheus_metrics'      => (bool)(getenv('FEATURE_PROMETHEUS') ?: true),
    'cdn_product'             => (bool)(getenv('FEATURE_CDN_PRODUCT') ?: true),
    'supplier_rating'         => (bool)(getenv('FEATURE_SUPPLIER_RATING') ?: true),
    'affiliate_program'       => (bool)(getenv('FEATURE_AFFILIATE') ?: true),
    'graphql_api'             => (bool)(getenv('FEATURE_GRAPHQL') ?: true),
];
