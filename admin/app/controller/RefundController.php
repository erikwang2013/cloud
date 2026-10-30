<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\model\Refund;
use GuzzleHttp\Client;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 退款管理
 */
class RefundController extends Crud
{
    /**
     * @var Refund
     */
    protected $model = null;

    public function __construct()
    {
        $this->model = new Refund;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('refund/index');
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
            // 后台只允许创建 pending 退款单；真正退款必须走审批执行内部端点
            $request->setPost('status', 'pending');
            return parent::insert($request);
        }
        return raw_view('refund/insert');
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
            if ($request->post('status') === 'refunded') {
                return $this->executeRefund($request);
            }
            return parent::update($request);
        }
        return raw_view('refund/update');
    }

    /**
     * 审批退款：调用 service 内部端点执行真实 Stripe 退款。
     * 失败时绝不回退为直写本地状态；成功由 service 落库。
     */
    private function executeRefund(Request $request): Response
    {
        $rawId = $request->post('id');
        $id = is_string($rawId) && !is_numeric($rawId) ? hashids_decode($rawId) : (int) $rawId;
        $refund = Refund::find($id);
        if (!$refund) {
            return $this->fail('退款单不存在');
        }
        if ($refund->status === 'refunded') {
            return $this->success();
        }

        // service /internal/v1 强制令牌，缺失时直接失败，避免审批字段更新后才报“服务不可用”
        $token = getenv('INTERNAL_MONITOR_TOKEN');
        if (!$token) {
            return $this->fail('内部退款令牌未配置');
        }

        // 审批时可修改金额/原因：仅同步这两个字段，业务合法性由 service amountError 单点校验
        $original = ['amount' => $refund->amount, 'reason' => $refund->reason];
        $localChanged = false;
        if (in_array($refund->status, ['pending', 'failed'], true)) {
            $data = [];
            $amount = $request->post('amount');
            if ($amount !== null && $amount !== '') {
                if (!preg_match('/^\d+(\.\d{1,4})?$/D', (string) $amount)) {
                    return $this->fail('退款金额格式不正确');
                }
                if (bccomp((string) $amount, (string) $refund->amount, 4) !== 0) {
                    $data['amount'] = (string) $amount;
                }
            }
            $reason = $request->post('reason');
            if ($reason !== null && (string) $reason !== (string) $refund->reason) {
                $data['reason'] = (string) $reason;
            }
            if ($data) {
                $refund->update($data);
                $localChanged = true;
            }
        }

        // 远程失败时恢复审批前金额/原因，避免本地与真实退款结果不一致
        $restore = function () use (&$localChanged, $refund, $original): void {
            if (!$localChanged) {
                return;
            }
            try {
                $refund->update($original);
            } catch (Throwable $e) {
                Log::error("Refund local restore failed: refund={$refund->id}: {$e->getMessage()}");
            }
            $localChanged = false;
        };

        $operatorId = (int) admin_id();
        $headers = [
            'Content-Type'     => 'application/json',
            'X-Internal-Token' => $token,
        ];

        try {
            $response = $this->refundHttpClient()->post(
                rtrim(getenv('SERVICE_INTERNAL_URL') ?: 'http://127.0.0.1:8787', '/') . '/internal/v1/refunds/execute',
                [
                    'json' => ['refund_id' => (int) $refund->id, 'operator_id' => $operatorId],
                    'headers' => $headers,
                ]
            );
            $body = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() >= 400) {
                Log::error("Refund execute HTTP {$response->getStatusCode()}: refund={$refund->id} order={$refund->order_id} admin={$operatorId}");
                $restore();
                return $this->fail('退款服务不可用，请稍后重试');
            }
            if (!is_array($body) || ($body['code'] ?? -1) !== 0) {
                $restore();
                return $this->fail((string) ($body['message'] ?? '退款失败'));
            }
        } catch (Throwable $e) {
            Log::error("Refund execute failed: refund={$refund->id} order={$refund->order_id} admin={$operatorId}: {$e->getMessage()}");
            $restore();
            return $this->fail('退款服务不可用，请稍后重试');
        }

        return $this->success();
    }

    protected function refundHttpClient(): Client
    {
        return new Client(['timeout' => 15, 'connect_timeout' => 3, 'http_errors' => false]);
    }
}
