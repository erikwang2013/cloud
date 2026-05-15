<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

use app\model\Base;

/**
 * @property integer $id ID(主键)
 * @property string $admin_id 管理员id
 * @property string $role_id 角色id
 */
class AdminRole extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_admin_roles';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
    
    
    public $timestamps = false;
    
}
