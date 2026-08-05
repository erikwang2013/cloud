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
     * 请求路径保持原样（如 /api/auth/login）。
     *
     * 注意：此前的版本重写逻辑会把 /api/xxx 改写为 /api/v1/xxx，
     * 但 config/route.php 注册的路由全部不带版本前缀，导致所有 API 端点 404。
     * API 版本校验由 Common\Version\Middleware\VersionMiddleware 基于 X-Api-Version 头完成。
     */
    public function path(): string
    {
        return parent::path();
    }
}
