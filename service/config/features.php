<?php
/*
 * Feature flags configuration.
 *
 * Each flag is keyed by name. Value can be:
 *   - bool: hardcoded default
 *   - env var: overridden at deploy time (0/false/off disables, 1/true/on enables)
 * Redis overrides take precedence and can be toggled at runtime via admin API.
 */

// env 显式设置时才覆盖默认值；'0'/'false'/'off' 均解析为 false
$env = static function (string $key, bool $default): bool {
    $raw = getenv($key);
    return $raw === false ? $default : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
};

return [
    'supplier_external_api'   => $env('FEATURE_SUPPLIER_API', false),
    'websocket_push'          => $env('FEATURE_WEBSOCKET', false),
    'maintenance_redirect'    => $env('FEATURE_MAINTENANCE_REDIRECT', false),
    'totp_two_factor'         => $env('FEATURE_TOTP', true),  // enabled by default
    'google_oauth'            => $env('FEATURE_GOOGLE_OAUTH', true),
    'apple_oauth'             => $env('FEATURE_APPLE_OAUTH', true),
    'facebook_oauth'          => $env('FEATURE_FACEBOOK_OAUTH', true),
    'x_oauth'                 => $env('FEATURE_X_OAUTH', true),
    'microsoft_oauth'         => $env('FEATURE_MICROSOFT_OAUTH', true),
    'linkedin_oauth'          => $env('FEATURE_LINKEDIN_OAUTH', true),
    'github_oauth'            => $env('FEATURE_GITHUB_OAUTH', true),
    'ssl_product'             => $env('FEATURE_SSL_PRODUCT', true),
    'object_storage_product'  => $env('FEATURE_OBJECT_STORAGE', true),
    'usage_billing'           => $env('FEATURE_USAGE_BILLING', true),
    'prometheus_metrics'      => $env('FEATURE_PROMETHEUS', true),
    'cdn_product'             => $env('FEATURE_CDN_PRODUCT', true),
    'supplier_rating'         => $env('FEATURE_SUPPLIER_RATING', true),
    'affiliate_program'       => $env('FEATURE_AFFILIATE', true),
    'graphql_api'             => $env('FEATURE_GRAPHQL', true),
];
