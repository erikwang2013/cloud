<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\common\Util;
use app\model\Option;
use support\exception\BusinessException;
use support\Request;
use support\Response;

use Throwable;

class IndexController
{

    /**
     * 无需登录的方法
     * @var string[]
     */
    protected $noNeedLogin = ['index'];

    /**
     * 不需要鉴权的方法
     * @var string[]
     */
    protected $noNeedAuth = ['dashboard'];

    /**
     * 后台主页
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function index(Request $request): Response
    {
        clearstatcache();
        if (!is_file(base_path('config/database.php'))) {
            return raw_view('index/install');
        }
        $admin = admin();
        if (!$admin) {
            $name = 'system_config';
            $config = Option::where('name', $name)->value('value');
            $config = json_decode($config, true);
            $title = $config['logo']['title'] ?? 'webman admin';
            $logo = $config['logo']['image'] ?? '/app/admin/admin/images/logo.png';
            return raw_view('account/login',['logo'=>$logo,'title'=>$title]);
        }
        return raw_view('index/index');
    }

    /**
     * 仪表板
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function dashboard(Request $request): Response
    {
        return raw_view('index/dashboard');
    }

}
