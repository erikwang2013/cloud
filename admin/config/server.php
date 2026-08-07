<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 监听地址和端口，生产环境通常前面加 Nginx 反向代理
    'listen'               => 'http://0.0.0.0:8788',

    // 传输协议：tcp（生产环境推荐）
    'transport'            => 'tcp',

    // 额外的 stream context 选项（SSL 证书等）
    'context'              => [],

    // Worker 进程显示名称（ps aux 可见）
    'name'                 => 'CloudAdmin',

    // Worker 进程数：4 个，建议设为 CPU 核心数，IO 密集型可适当增加
    'count'                => 4,

    // 以指定用户运行（生产环境建议配置非 root 用户）
    'user'                 => '',

    // 以指定用户组运行
    'group'                => '',

    // 端口复用：true 时多个 worker 可绑定同一端口（Linux 3.9+）
    'reuse_port'           => true,

    // 事件循环（留空自动选择最佳：Linux 用 Event，Windows 用 Select）
    'event_loop'           => '',

    // 收到 SIGTERM 后等待 worker 处理完当前请求的秒数
    'stop_timeout'         => 2,

    // PID 文件路径，用于后台进程管理（start/stop/restart/reload）
    'pid_file'             => runtime_path() . '/webman.pid',

    // 状态文件，记录 worker 运行状态
    'status_file'          => runtime_path() . '/webman.status',

    // 标准输出重定向文件（后台运行时）
    'stdout_file'          => runtime_path() . '/logs/stdout.log',

    // Workerman 自身日志文件
    'log_file'             => runtime_path() . '/logs/workerman.log',

    // 最大请求包大小：10MB，防止大包攻击
    'max_package_size'     => 10 * 1024 * 1024,
];
