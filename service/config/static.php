<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

/**
 * 静态文件服务配置（与 admin/config/static.php 保持一致）。
 *
 * 缺失本文件时 webman 取默认值 static.enable=false，public/ 下的资源一律 404，
 * 落地页引用的 /favicon.svg、/mascot.svg、/favicon.ico 也就无法访问。
 * 生产环境若由 nginx 直接托管静态资源，可将 enable 置 false 并配置 nginx location。
 */
return [
    'enable' => true,
    'middleware' => [],
];
