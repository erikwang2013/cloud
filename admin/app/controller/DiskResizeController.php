<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\model\DiskResize;
use support\Request;
use support\Response;
use Throwable;

/**
 * 磁盘扩容管理
 */
class DiskResizeController extends Crud
{
    /**
     * @var DiskResize
     */
    protected $model = null;

    public function __construct()
    {
        $this->model = new DiskResize;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('disk_resize/index');
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::insert($request);
        }
        return raw_view('disk_resize/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::update($request);
        }
        return raw_view('disk_resize/update');
    }
}
