<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

/**
 * 业务用户模型（users 表）。
 * 注意：admin 的 User 模型指向 wa_users（管理员表），业务用户是另一张表，勿混用。
 */
class BusinessUser extends Base
{
    protected $table = 'users';
    protected $fillable = ['email', 'phone', 'password_hash', 'language', 'currency', 'timezone', 'status', 'role', 'notification_prefs'];
}
