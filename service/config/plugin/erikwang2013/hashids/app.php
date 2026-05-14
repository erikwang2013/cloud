<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 启用 hashids 插件：将数字 ID 混淆为短字符串，隐藏真实规模
    // 自动生效：API 响应中的 id 字段会被 hashid_encode，请求中会被 hashid_decode
    'enable' => true,
];
