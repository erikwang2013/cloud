<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 启用 season 插件：国家旗帜 emoji / 季节检测 / 多语言季节名称
    'enable' => true,

    // 默认国家代码（ISO 3166-1 alpha-2），可通过环境变量 COUNTRY_SEASON_DEFAULT 覆盖
    'default_country_code' => getenv('COUNTRY_SEASON_DEFAULT') ?: 'CN',
];
