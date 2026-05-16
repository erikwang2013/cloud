<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests\Support;

use app\controller\Crud;
use app\model\Admin;
use support\Request;

/**
 * A Crud subclass that bypasses DB and Auth so hashids decode logic
 * can be tested in isolation.
 *
 * Uses the real Admin model (wa_admins table) as the underlying model
 * but overrides the DB-depending methods so no actual connection is needed.
 */
final class TestableCrud extends Crud
{
    public function __construct()
    {
        $this->model = new Admin;
    }

    /**
     * Skip the real DB table-description query; return a minimal set of
     * known columns so the column-filter logic works.
     */
    protected function selectInput(Request $request): array
    {
        $field = $request->get('field');
        $order = $request->get('order', 'asc');
        $format = $request->get('format', 'normal');
        $limit = (int) $request->get('limit', $format === 'tree' ? 1000 : 10);
        $limit = $limit <= 0 ? 10 : $limit;
        $order = $order === 'asc' ? 'asc' : 'desc';
        $where = $request->get();

        // Hashids decode — the logic under test.
        foreach ($where as $column => $value) {
            if (
                is_string($value)
                && !is_numeric($value)
                && ($column === 'id' || str_ends_with($column, '_id'))
            ) {
                $where[$column] = hashids_decode($value);
            }
        }

        $page = (int) $request->get('page');
        $page = $page > 0 ? $page : 1;

        // Known columns — bypasses real DB lookup.
        $allow_column = [
            'id' => 'id',
            'username' => 'username',
            'nickname' => 'nickname',
            'email' => 'email',
            'mobile' => 'mobile',
            'admin_id' => 'admin_id',
            'role_id' => 'role_id',
        ];

        foreach ($where as $column => $value) {
            if (
                $value === '' || !isset($allow_column[$column]) ||
                (is_array($value) && (empty($value) || !in_array($value[0], ['null', 'not null']) && !isset($value[1])))
            ) {
                unset($where[$column]);
            }
        }

        return [$where, $format, $limit, $field, $order, $page];
    }

    /**
     * Skip the DB table-description query in inputFilter; pass data through.
     */
    protected function inputFilter(array $data): array
    {
        return $data;
    }

    /**
     * Override updateInput to bypass model->find() which needs a database.
     */
    protected function updateInput(Request $request): array
    {
        $primary_key = $this->model->getKeyName();
        $raw_id = $request->post($primary_key);
        $id = is_string($raw_id) && !is_numeric($raw_id) ? hashids_decode($raw_id) : (int) $raw_id;
        $data = $this->inputFilter($request->post());

        $password_field = 'password';
        if (isset($data[$password_field])) {
            if ($data[$password_field] === '') {
                unset($data[$password_field]);
            } else {
                $data[$password_field] = password_hash($data[$password_field], PASSWORD_BCRYPT);
            }
        }
        unset($data[$primary_key]);
        return [$id, $data, null];
    }

    /**
     * Override deleteInput to bypass Auth/dataLimit DB queries.
     */
    protected function deleteInput(Request $request): array
    {
        $primary_key = $this->model->getKeyName();
        if (!$primary_key) {
            throw new \support\exception\BusinessException('该表无主键，不支持删除');
        }
        $ids = (array) $request->post($primary_key, []);
        $ids = array_map(fn($v) => is_string($v) && !is_numeric($v) ? hashids_decode($v) : (int) $v, $ids);
        return $ids;
    }
}
