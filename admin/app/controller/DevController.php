<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use support\Response;
use Throwable;

/**
 * 开发辅助相关
 */
class DevController
{
    /**
     * 表单构建
     * @return Response
     * @throws Throwable
     */
    public function formBuild()
    {
        return raw_view('dev/form-build');
    }

}
