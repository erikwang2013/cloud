<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace support\exception;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 全局异常处理器：项目级覆盖框架默认实现。
 * ModelNotFoundException（firstOrFail/findOrFail 未命中）统一映射为 404，
 * 避免公开端点（products/{id}、help/{slug}、stripe webhook 等）返回 500。
 * InvalidArgumentException（如 HashidService::decodeId 非法 ID）统一映射为 422，
 * 与既有 HTTP 200 + code 422 信封约定一致；但不加入 dontReport，异常仍进日志，
 * 避免编程错误被 422 静默吞掉。
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
            return json(\Common\helper\Response::error(404, 'Resource not found'));
        }
        if ($exception instanceof InvalidArgumentException) {
            return json(\Common\helper\Response::error(422, $exception->getMessage()));
        }
        return parent::render($request, $exception);
    }
}
