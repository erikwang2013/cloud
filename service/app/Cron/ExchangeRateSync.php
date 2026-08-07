<?php
namespace App\Cron;

class ExchangeRateSync
{
    public function run(): void
    {
        $apiUrl = getenv('EXCHANGE_RATE_API_URL') ?: 'https://api.exchangerate-api.com/v4/latest/USD';
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
            $response = file_get_contents($apiUrl, false, $ctx);
            $data = json_decode($response, true);
            if (!empty($data['rates'])) {
                \Illuminate\Support\Facades\Redis::set('exchange_rates', json_encode($data['rates']));
                echo date('Y-m-d H:i:s') . " Exchange rates updated. Currencies: " . count($data['rates']) . "\n";
            }
        } catch (\Throwable $e) {
            echo date('Y-m-d H:i:s') . " ExchangeRateSync failed: " . $e->getMessage() . "\n";
        }
    }
}
