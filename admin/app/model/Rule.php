<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

use app\model\Base;

/**
 * @property integer $id 主键(主键)
 * @property string $title 标题
 * @property string $icon 图标
 * @property string $key 标识
 * @property integer $pid 上级菜单
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $href url
 * @property integer $type 类型
 * @property integer $weight 排序
 */
class Rule extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_rules';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
    
    
    
    
}
