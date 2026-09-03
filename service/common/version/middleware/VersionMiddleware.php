<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

namespace Common\version\middleware;

use Common\helper\Response;

class VersionMiddleware
{
    /**
     * Supported API versions.
     */
    protected const SUPPORTED = ['v1'];

    /**
     * API 版本段位于 URL 路径：/api/{version}/... 与 /admin/api/{version}/...
     * (不再通过 X-Api-Version 请求头传递)
     */
    private const PATH_PATTERN = '#^/(?:admin/api|api)/([^/]+)#';

    /**
     * Read version from URL path, validate, and store for downstream controllers.
     */
    public function process($request, callable $next)
    {
        $path = $request->path();

        // 非 /api、/admin/api 路径（/health、/graphql 等）不校验版本
        if (!preg_match(self::PATH_PATTERN, $path, $m)) {
            return $next($request);
        }

        $version = $m[1];

        if (!in_array($version, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported API version: {$version}. Supported: " . implode(', ', static::SUPPORTED))
            ), 400, [
                'Content-Type' => 'application/json',
            ]);
        }

        // Store version for downstream controllers
        $request->properties['api_version'] = $version;

        return $next($request);
    }
}
