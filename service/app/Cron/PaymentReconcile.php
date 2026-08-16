<?php
namespace App\Cron;

class PaymentReconcile
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " PaymentReconcile: Starting daily reconciliation...\n";

        $channels = \App\Payment\Model\PaymentChannel::where('status', 'active')->get();
        $today    = date('Y-m-d');
        $table    = \Illuminate\Database\Capsule\Manager::table('payment_reconcile');
        $hasExternal = false;

        foreach ($channels as $channel) {
            try {
                $channelTotal = \App\Payment\Model\PaymentTransaction::where('channel_id', $channel->id)
                    ->whereDate('created_at', $today)
                    ->where('status', 'success')
                    ->sum('amount');

                // 外部通道报表是否已配置（如 Stripe Secret Key）
                if (!empty($channel->api_key_encrypted)) {
                    $hasExternal = true;
                }

                // 真实对账需要拉取通道侧报表并与本地逐笔比对（Phase 1 接入）。
                // 当前未实现拉取，禁止把"本地自比对"伪装成已核对：显式置 unverified，
                // 让管理端能发现对账并未真正完成。
                $table->updateOrInsert(
                    ['channel_id' => $channel->id, 'date' => $today],
                    [
                        'channel_total' => $channelTotal,
                        'system_total'  => $channelTotal,
                        'diff'          => 0,
                        'status'        => 'unverified',
                    ]
                );

                echo "  Channel {$channel->code}: local total {$channelTotal} — 未拉取通道报表，记录置 unverified（真实对账待接入）\n";
            } catch (\Throwable $e) {
                echo "  Channel {$channel->code}: ERROR - {$e->getMessage()}\n";
            }
        }

        if (!$hasExternal) {
            echo "  WARNING: 所有通道均未配置外部 API 密钥，无法进行真实对账\n";
        }

        echo date('Y-m-d H:i:s') . " PaymentReconcile: Done.\n";
    }
}
