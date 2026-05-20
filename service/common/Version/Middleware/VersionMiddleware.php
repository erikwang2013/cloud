<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

namespace Common\Version\Middleware;

use Common\Helper\Response;

class VersionMiddleware
{
    /**
     * Supported API versions.
     */
    protected const SUPPORTED = ['v1'];

    /**
     * Read X-Api-Version header, validate, store, and echo back in response.
     */
    public function process($request, callable $next)
    {
        $path = $request->path();

        // Only validate for API routes
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $version = $request->header('X-Api-Version', 'v1');

        if (!in_array($version, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported API version: {$version}. Supported: " . implode(', ', static::SUPPORTED))
            ), 400, [
                'Content-Type'  => 'application/json',
                'X-Api-Version' => $version,
            ]);
        }

        // Store version for downstream controllers
        $request->properties['api_version'] = $version;

        $response = $next($request);

        // Echo API version back to client for debugging
        $response->header('X-Api-Version', $version);

        return $response;
    }
}
