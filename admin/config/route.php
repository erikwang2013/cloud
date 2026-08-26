<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use app\controller\AccountController;
use app\controller\DictController;
use Webman\Route;
use support\Request;

/**
 * Custom route definitions.
 *
 * Most routes are auto-resolved from the URL path to controller + action.
 * These routes override or supplement the auto-resolution:
 *
 *   /app/admin/account/captcha/{type}  -> AccountController::captcha()
 *   /app/admin/dict/get/{name}         -> DictController::get()
 */

/** Captcha image endpoint — supports login and other captcha types. */
Route::any('/app/admin/account/captcha/{type}', [AccountController::class, 'captcha']);

/** Dictionary lookup by name — returns key-value pairs as JSON. */
Route::any('/app/admin/dict/get/{name}', [DictController::class, 'get']);

/** Dashboard data API — returns JSON for stat cards and ECharts. */
Route::any('/app/admin/dashboard/data', [app\controller\DashboardController::class, 'index']);

/** Excel export for generic tables — downloads filtered data as .xlsx. */
Route::any('/app/admin/table/export', [app\controller\TableController::class, 'export']);

/** 后台主页 / 登录页 — 未登录时 IndexController::index 渲染登录视图。 */
Route::any('/app/admin', [app\controller\IndexController::class, 'index']);
Route::any('/app/admin/index', [app\controller\IndexController::class, 'index']);

/**
 * 通用 CRUD 路由：为 app\controller 下每个控制器注册其公开方法，
 * URL 采用菜单使用的 snake_case 控制器名（/app/admin/order_item/index）。
 * 显式注册的 [Class, method] 回调会让 webman 正确填充 $request->controller/action，
 * AccessControl 中间件据此做登录与 RBAC 鉴权。
 */
$registeredPaths = [
    '/app/admin/account/captcha/{type}',
    '/app/admin/dict/get/{name}',
    '/app/admin/dashboard/data',
    '/app/admin/table/export',
];
foreach (glob(__DIR__ . '/../app/controller/*Controller.php') as $file) {
    $class = 'app\\controller\\' . basename($file, '.php');
    $snake = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', basename($file, 'Controller.php')));
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $action = $method->getName();
        if (str_starts_with($action, '__')) {
            continue;
        }
        $callable = true;
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && is_a($type->getName(), Request::class, true)) {
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                continue;
            }
            $callable = false;
            break;
        }
        if (!$callable) {
            continue;
        }
        $actionVariants = [$action];
        if (($kebab = preg_replace('/(?<!^)([A-Z])/', '-$1', $action)) !== $action) {
            $actionVariants[] = $kebab;
        }
        foreach ($actionVariants as $variant) {
            $path = "/app/admin/$snake/$variant";
            if (in_array($path, $registeredPaths, true)) {
                continue;
            }
            $registeredPaths[] = $path;
            Route::any($path, [$class, $action]);
        }
    }
}

/** Fallback: return 404 for all un-matched requests within this scope. */
Route::fallback(function (Request $request) {
    return response('', 404);
});
