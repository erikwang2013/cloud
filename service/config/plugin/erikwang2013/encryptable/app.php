<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 启用 encryptable 插件：提供 Eloquent 模型字段级加密/解密
    // 使用方式：在模型的 $casts 中添加字段 => Encryptable::class
    'enable' => true,
];
