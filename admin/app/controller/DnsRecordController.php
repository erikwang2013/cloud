<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\model\DnsRecord;
use support\Request;
use support\Response;
use Throwable;

/**
 * DNS记录管理
 */
class DnsRecordController extends Crud
{
    /**
     * @var DnsRecord
     */
    protected $model = null;

    public function __construct()
    {
        $this->model = new DnsRecord;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('dns_record/index');
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
        return raw_view('dns_record/insert');
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
        return raw_view('dns_record/update');
    }
}
