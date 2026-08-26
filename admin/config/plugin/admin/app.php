<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Plugin "admin" 配置 — 让 webman 把 /app/admin/* 的 URL 前缀当作
 * 插件静态根解析到本项目自己的 public/ 目录（视图引用 /app/admin/admin/... 等资源）。
 */
return [
    'enable'            => true,
    'controller_suffix' => 'Controller',
    'public_path'       => base_path() . '/public',
];
