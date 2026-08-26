<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace support\exception;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 全局异常处理器：项目级覆盖框架默认实现。
 * ModelNotFoundException（firstOrFail/findOrFail 未命中）统一映射为 404，
 * 避免公开端点（products/{id}、help/{slug}、stripe webhook 等）返回 500。
 */
class Handler extends \Webman\Exception\ExceptionHandler
{
    public $dontReport = [
        ModelNotFoundException::class,
        BusinessException::class,
    ];

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ModelNotFoundException) {
            return json(\Common\Helper\Response::error(404, 'Resource not found'));
        }
        return parent::render($request, $exception);
    }
}
