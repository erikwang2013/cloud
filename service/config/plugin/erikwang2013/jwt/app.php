<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 启用 JWT 插件：缺失此文件会导致 webman Config::loadFromDir 跳过整个
    // config/plugin/erikwang2013/jwt 目录，config('plugin.erikwang2013.jwt') 返回 null
    'enable' => true,
];
