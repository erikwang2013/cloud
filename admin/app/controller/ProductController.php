<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\model\Product;
use support\Request;
use support\Response;
use Throwable;

/**
 * 产品管理
 */
class ProductController extends Crud
{
    /**
     * @var Product
     */
    protected $model = null;

    public function __construct()
    {
        $this->model = new Product;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('product/index');
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
        return raw_view('product/insert');
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
        return raw_view('product/update');
    }
}
