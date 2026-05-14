<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 应用名称，展示在页面标题、邮件、通知等处
    'name'              => getenv('APP_NAME') ?: 'CloudPlatform',

    // 调试模式：true 时显示详细错误信息，生产环境必须为 false
    'debug'             => getenv('APP_DEBUG') === 'true',

    // 默认时区，影响所有日期时间处理（日志、订单时间等）
    'default_timezone'  => getenv('APP_TIMEZONE') ?: 'UTC',

    // 默认语言区域，未匹配用户偏好时使用
    'locale'            => 'en-US',

    // 回退语言区域，当翻译 key 在首选语言中缺失时使用
    'fallback_locale'   => 'en-US',

    // 支持的货币列表，用于订单结算、余额、供应商结算
    'currencies'        => ['USD', 'CNY', 'EUR', 'JPY', 'GBP'],

    // 基准货币，所有内部金额计算、汇率换算的基础货币
    'base_currency'     => 'USD',

    // PHP 错误报告级别，E_ALL & ~E_DEPRECATED 可屏蔽废弃警告
    'error_reporting'   => E_ALL & ~E_DEPRECATED,

    // webman 请求类，不可修改
    'request_class'     => Webman\Http\Request::class,
];
