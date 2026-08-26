<?php
namespace Common\auth\middleware;

use App\supplier\model\SupplierApiKey;
use Common\helper\Response;

class SupplierApiKeyMiddleware
{
    public function process($request, callable $next)
    {
        $header = $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer sk_')) {
            return json(Response::error(401, 'Missing or invalid API key format'));
        }

        $rawKey = substr($header, 7); // strip 'Bearer '
        $keyHash = hash('sha256', $rawKey);

        $apiKey = SupplierApiKey::where('key_hash', $keyHash)->first();

        if (!$apiKey || $apiKey->revoked) {
            return json(Response::error(401, 'Invalid or revoked API key'));
        }

        // Inject supplier context
        $request->supplierId = $apiKey->supplier_id;
        $request->apiKeyId   = $apiKey->id;

        // Update last_used_at (non-critical, fire-and-forget)
        try {
            SupplierApiKey::where('id', $apiKey->id)->update(['last_used_at' => now()]);
        } catch (\Throwable $e) {
            // Non-critical
        }

        return $next($request);
    }
}
