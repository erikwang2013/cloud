<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 默认日志通道
    'default' => [
        'handlers' => [
            [
                // 按天轮转日志文件，自动保留最近 30 天
                'class'       => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/webman.log',  // 日志文件路径
                    30,                                   // 保留天数
                    Monolog\Logger::DEBUG,                 // 最低记录级别：DEBUG
                ],
                'formatter' => [
                    // 行格式：时间戳 + 消息，不显示调用栈（生产模式）
                    'class'       => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [null, 'Y-m-d H:i:s', true],
                ],
            ],
        ],
    ],
];
