<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

namespace Common\Version;

use Webman\Http\Request;

class Version
{
    /**
     * Get the current API version from the request.
     *
     * Returns the validated version string (e.g. 'v1'), or 'v1' as default
     * for non-API requests.
     */
    public static function current(Request $request): string
    {
        return $request->properties['api_version'] ?? 'v1';
    }

    /**
     * Check if the current request targets a specific API version.
     */
    public static function is(Request $request, string $version): bool
    {
        return static::current($request) === $version;
    }

    /**
     * Get all supported API versions.
     *
     * @return string[]
     */
    public static function supported(): array
    {
        return ['v1'];
    }
}
