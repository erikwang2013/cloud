<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 数据库字段加密主密钥（16 字节，base64 编码）
    // 一旦设定请不要更改，否则已加密数据将无法解密
    // 生成方式：echo -n "$(openssl rand -base64 16)" | base64 -w0
    'key'           => getenv('ENCRYPTION_KEY') ?: '',

    // 加密算法：aes-128-ecb（确定性加密 — 相同明文产生相同密文）
    // ECB 模式使加密列可查询（WHERE email = 'xxx'），但会泄漏等值模式
    // 如需隐蔽模式可用 aes-256-cbc，但搜不到已加密字段
    'cipher'        => getenv('ENCRYPTION_CIPHER') ?: 'aes-128-ecb',

    // 旧密钥列表（逗号分隔），用于零停机密钥轮换
    // 解密时依次尝试所有密钥，重加密时使用主密钥
    // 示例：ENCRYPTION_PREVIOUS_KEYS="oldkey1base64,oldkey2base64"
    'previous_keys' => array_filter(explode(',', getenv('ENCRYPTION_PREVIOUS_KEYS') ?: '')),
];
