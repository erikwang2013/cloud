<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 用户密码哈希策略
    'password' => [
        // 哈希算法：PASSWORD_BCRYPT = bcrypt，生产环境唯一推荐
        'algo'  => PASSWORD_BCRYPT,

        // bcrypt cost 因子：12 表示 2^12 次迭代，兼顾安全与性能
        'cost'  => 12,

        // 密码最小长度，注册和修改密码时校验
        'min_length' => 8,
    ],

    // 多因素认证 (TOTP) 配置
    'mfa' => [
        // TOTP 签发者，在 Google/Microsoft Authenticator 中显示的名称
        'issuer' => 'CloudPlatform',

        // 验证码位数：6 位数字
        'digits' => 6,

        // 验证码刷新周期：30 秒
        'period' => 30,

        // 哈希算法：sha1 兼容性最好
        'algo'   => 'sha1',
    ],
];
