<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

use Erikwang2013\WebmanScout\Searchable;
use Maize\Encryptable\Encryptable;

/**
 * @property integer $id 主键(主键)
 * @property string $username 用户名
 * @property string $nickname 昵称
 * @property string $password 密码
 * @property string $sex 性别
 * @property string $avatar 头像
 * @property string $email 邮箱
 * @property string $mobile 手机
 * @property integer $level 等级
 * @property string $birthday 生日
 * @property integer $money 余额
 * @property integer $score 积分
 * @property string $last_time 登录时间
 * @property string $last_ip 登录ip
 * @property string $join_time 注册时间
 * @property string $join_ip 注册ip
 * @property string $token token
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property integer $role 角色
 * @property integer $status 禁用
 */
class User extends Base
{
    use Searchable;

    /**
     * The table associated with the model.
     */
    protected $table = 'wa_users';

    /**
     * The primary key associated with the table.
     */
    protected $primaryKey = 'id';

    /**
     * Encrypted attributes — transparently encrypted on write, decrypted on read.
     */
    protected $casts = [
        'password' => Encryptable::class,
        'email' => Encryptable::class,
        'mobile' => Encryptable::class,
        'token' => Encryptable::class,
        'last_ip' => Encryptable::class,
        'join_ip' => Encryptable::class,
    ];

    /**
     * Get the data that should be indexed in the search engine.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
