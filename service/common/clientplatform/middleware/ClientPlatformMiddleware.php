<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

namespace Common\clientplatform\middleware;

use Common\helper\Response;

class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        $path = $request->path();

        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}. Supported: " . implode(', ', static::SUPPORTED))
            ), 400, [
                'Content-Type'      => 'application/json',
                'X-Client-Platform' => $platform,
            ]);
        }

        $request->properties['client_platform'] = $platform;

        $response = $next($request);

        $response->header('X-Client-Platform', $platform);

        return $response;
    }
}
