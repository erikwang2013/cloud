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

    // 数据库字段加密主密钥（base64 编码；长度取决于 cipher：aes-128-ecb=16 字节 / aes-256-cbc=32 字节）
    // 一旦设定请不要更改，否则已加密数据将无法解密
    // 生成方式：openssl rand -base64 32
    // 注意：env 中为 base64 编码的原始密钥，必须解码后传给加密库（直接传 base64 串会抛 MissingEncryptionKeyException）
    'key'           => base64_decode((string) getenv('ENCRYPTION_KEY'), true) ?: '',

    // 加密算法：默认 aes-256-gcm；.env 配置为 aes-128-ecb（16 字节密钥）
    'cipher'        => getenv('ENCRYPTION_CIPHER') ?: 'aes-256-gcm',

    // 旧密钥列表（逗号分隔 base64），用于零停机密钥轮换；.env 未配置时为空
    'previous_keys' => \Erikwang2013\Encryptable\Support\PreviousKeysParser::parse(getenv('ENCRYPTION_PREVIOUS_KEYS') ?: ''),
];
