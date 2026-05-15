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

/** Fallback: return 404 for all un-matched requests within this scope. */
Route::fallback(function (Request $request) {
    return response('', 404);
});
