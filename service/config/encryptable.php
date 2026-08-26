<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 数据库字段加密主密钥（base64 编码；长度取决于 cipher：aes-128-ecb=16 字节 / aes-256-cbc=32 字节）
    // 一旦设定请不要更改，否则已加密数据将无法解密
    // 生成方式：openssl rand -base64 32
    // 注意：env 中为 base64 编码的原始密钥，必须解码后传给加密库（直接传 base64 串会抛 MissingEncryptionKeyException）
    'key'           => base64_decode((string) getenv('ENCRYPTION_KEY'), true) ?: '',

    // 加密算法：aes-256-cbc（随机 IV — 相同明文产生不同密文，不泄漏等值模式）
    // 注意：存量以 aes-128-ecb 加密的数据无法用 CBC 解密，升级需重加密迁移
    //（读出明文 → 换 cipher 后写回）；encryptable 包支持任何 OpenSSL cipher + 随机 IV
    'cipher'        => getenv('ENCRYPTION_CIPHER') ?: 'aes-256-cbc',

    // 旧密钥列表（逗号分隔），用于零停机密钥轮换
    // 解密时依次尝试所有密钥，重加密时使用主密钥
    // 示例：ENCRYPTION_PREVIOUS_KEYS="oldkey1base64,oldkey2base64"
    'previous_keys' => array_filter(explode(',', getenv('ENCRYPTION_PREVIOUS_KEYS') ?: '')),
];
