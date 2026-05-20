<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

namespace Common\Http;

use Webman\Http\Request;

class ApiRequest extends Request
{
    /**
     * Get path with API version prefix rewritten from X-Api-Version header.
     *
     * External URLs omit the version segment (e.g. /api/auth/login).
     * The version is carried in the X-Api-Version header (default: v1).
     * This method rewrites the path internally so versioned routes still match.
     */
    public function path(): string
    {
        $path = parent::path();

        // /api/xxx -> /api/{version}/xxx
        if (str_starts_with($path, '/api/') && !preg_match('#^/api/v\d+/#', $path)) {
            $version = $this->header('X-Api-Version', 'v1');
            return preg_replace('#^(/api)/#', '$1/' . $version . '/', $path);
        }

        // /admin/api/xxx -> /admin/api/{version}/xxx
        if (str_starts_with($path, '/admin/api/') && !preg_match('#^/admin/api/v\d+/#', $path)) {
            $version = $this->header('X-Api-Version', 'v1');
            return preg_replace('#^(/admin/api)/#', '$1/' . $version . '/', $path);
        }

        return $path;
    }
}
