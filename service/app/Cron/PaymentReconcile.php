<?php
namespace App\Cron;

class PaymentReconcile
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " PaymentReconcile: Starting daily reconciliation...\n";

        $channels = \App\Payment\Model\PaymentChannel::where('status', 'active')->get();
        $today    = date('Y-m-d');

        foreach ($channels as $channel) {
            try {
                $channelTotal = \App\Payment\Model\PaymentTransaction::where('channel_id', $channel->id)
                    ->whereDate('created_at', $today)
                    ->where('status', 'success')
                    ->sum('amount');

                // Mark unreconciled if existing record with diff > 0.01
                $existing = \Illuminate\Database\Capsule\Manager::table('payment_reconcile')
                    ->where('channel_id', $channel->id)
                    ->where('date', $today)
                    ->first();

                $diff = $existing ? abs((float) $existing->channel_total - (float) $channelTotal) : 0;

                if (!$existing || $diff > 0.01) {
                    \Illuminate\Database\Capsule\Manager::table('payment_reconcile')->updateOrInsert(
                        ['channel_id' => $channel->id, 'date' => $today],
                        ['channel_total' => $channelTotal, 'system_total' => $channelTotal, 'diff' => $diff, 'status' => $diff > 0.01 ? 'unreconciled' : 'reconciled']
                    );
                }
            } catch (\Throwable $e) {
                echo "  Channel {$channel->code}: ERROR - {$e->getMessage()}\n";
            }
        }

        echo date('Y-m-d H:i:s') . " PaymentReconcile: Done.\n";
    }
}
