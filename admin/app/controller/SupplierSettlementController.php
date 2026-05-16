<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\model\SupplierSettlement;
use support\Request;
use support\Response;
use Throwable;

/**
 * 供应商结算管理
 */
class SupplierSettlementController extends Crud
{
    /**
     * @var SupplierSettlement
     */
    protected $model = null;

    public function __construct()
    {
        $this->model = new SupplierSettlement;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('supplier_settlement/index');
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
        return raw_view('supplier_settlement/insert');
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
        return raw_view('supplier_settlement/update');
    }
}
