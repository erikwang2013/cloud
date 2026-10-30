<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

use Webman\Route;
use Common\version\middleware\VersionMiddleware;

// Health check (no version check, public)
Route::get('/health', [App\controller\HealthController::class, 'index']);

// 站点落地页。webman 的静态文件处理只认精确文件（App::findFile 对 "/" 得到目录、
// is_file 为 false），没有目录索引逻辑，因此 "/" 必须由路由回吐 public/index.html。
Route::get('/', function () {
    $page = base_path() . '/public/index.html';
    if (!is_file($page)) {
        return response('', 404);
    }
    return response(file_get_contents($page), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
});

// Health check (internal monitoring, token-protected)
Route::group('/health', function () {
    Route::get('/live', [App\controller\HealthController::class, 'live']);
    Route::get('/ready', [App\controller\HealthController::class, 'ready']);
})->middleware([Common\security\InternalTokenMiddleware::class]);

// 依赖详情包含内部错误信息：不给 loopback 旁路，必须显式 token
Route::get('/health/deps', [App\controller\HealthController::class, 'deps'])
    ->middleware([Common\security\InternalTokenRequiredMiddleware::class]);

// Auth routes (with version + encryption + rate limiting + captcha verification on sensitive ops)
Route::group('/api/v1/auth', function () {
    Route::post('/register', [App\user\controller\AuthController::class, 'register']);
    Route::post('/login', [App\user\controller\AuthController::class, 'login']);
    Route::post('/refresh', [App\user\controller\AuthController::class, 'refresh']);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class]);

// Password reset (public)
Route::post('/api/v1/auth/forgot-password', [App\user\controller\AuthController::class, 'forgotPassword']);
Route::post('/api/v1/auth/reset-password', [App\user\controller\AuthController::class, 'resetPassword']);
// Email verification (public)
Route::get('/api/v1/auth/verify-email', [App\user\controller\AuthController::class, 'verifyEmail']);

// Service status (public)
Route::get('/api/v1/status', [App\controller\StatusController::class, 'index']);

// OAuth (public) — generic provider routes: google, apple, facebook, x, microsoft, linkedin, github
// POST callback is required by Apple (response_mode=form_post)
Route::get('/api/v1/auth/{provider}', [App\user\controller\OAuthController::class, 'oauthRedirect']);
Route::get('/api/v1/auth/{provider}/callback', [App\user\controller\OAuthController::class, 'oauthCallback']);
Route::post('/api/v1/auth/{provider}/callback', [App\user\controller\OAuthController::class, 'oauthCallback']);
// TOTP recovery login (public)
Route::post('/api/v1/auth/login/recovery', [App\user\controller\AuthController::class, 'loginWithRecoveryCode']);
// SMS verification (public)
Route::post('/api/v1/auth/send-sms', [App\user\controller\AuthController::class, 'sendSmsVerify']);
// Captcha route (public, generates click captcha for login/register)
Route::post('/api/v1/captcha/create', [App\captcha\controller\CaptchaController::class, 'create'])
    ->middleware([Common\encryption\middleware\EncryptionMiddleware::class]);

// Product routes (public)
Route::get('/api/v1/products', [App\product\controller\ProductController::class, 'index']);
Route::get('/api/v1/products/search', [App\product\controller\ProductController::class, 'search']);
Route::get('/api/v1/products/{id}', [App\product\controller\ProductController::class, 'show']);
Route::get('/api/v1/regions', [App\product\controller\ProductController::class, 'regions']);
Route::get('/api/v1/products/{productId}/reviews', [App\product\controller\ReviewController::class, 'index']);

// Domain routes (public)
Route::get('/api/v1/domain/check/{domain}/{tld}', [App\domain\controller\DomainController::class, 'check']);
Route::get('/api/v1/domain/tlds', [App\domain\controller\DomainController::class, 'tlds']);

// Help articles (public)
Route::get('/api/v1/help', [App\controller\HelpController::class, 'index']);
Route::get('/api/v1/help/categories', [App\controller\HelpController::class, 'categories']);
Route::get('/api/v1/help/{slug}', [App\controller\HelpController::class, 'show']);

// Supplier ratings (public)
Route::get('/api/v1/suppliers/{supplierId}/ratings', [App\supplier\controller\SupplierRatingController::class, 'supplierRatings']);

// Payment webhooks (no auth, signature verified)
Route::post('/api/v1/payments/webhook/stripe', [App\payment\controller\PaymentController::class, 'stripeWebhook']);

// === User authenticated routes ===
Route::group('/api/v1', function () {
    // Profile
    Route::get('/user/profile', [App\user\controller\ProfileController::class, 'show']);
    Route::put('/user/profile', [App\user\controller\ProfileController::class, 'update']);
    Route::post('/user/kyc', [App\user\controller\KycController::class, 'submit']);

    // TOTP 2FA
    Route::post('/user/totp/setup', [App\user\controller\AuthController::class, 'totpSetup']);
    Route::post('/user/totp/verify', [App\user\controller\AuthController::class, 'totpVerify']);
    Route::post('/user/totp/disable', [App\user\controller\AuthController::class, 'totpDisable']);
    Route::get('/user/totp/recovery-codes', [App\user\controller\AuthController::class, 'totpRecoveryCodes']);

    // Sessions
    Route::get('/user/sessions', [App\user\controller\AuthController::class, 'sessions']);
    Route::delete('/user/sessions/{id}', [App\user\controller\AuthController::class, 'revokeSession']);

    // Email verification
    Route::post('/user/resend-verify-email', [App\user\controller\AuthController::class, 'resendVerifyEmail']);

    // Account
    Route::delete('/user/account', [App\user\controller\AuthController::class, 'deleteAccount']);

    // Coupon
    Route::post('/coupons/validate', [App\order\controller\CouponController::class, 'validate']);

    // Upload
    Route::post('/upload', [App\controller\UploadController::class, 'upload']);

    // Batch operations
    Route::post('/resources/batch', [App\provisioning\controller\BatchController::class, 'batchAction']);
    Route::get('/user/balance', [App\user\controller\BalanceController::class, 'index']);
    Route::get('/user/balance/transactions', [App\user\controller\BalanceController::class, 'transactions']);
    Route::get('/user/notifications', [App\notification\controller\NotificationController::class, 'index']);
    Route::post('/user/notifications/{id}/read', [App\notification\controller\NotificationController::class, 'markRead']);

    // Addresses
    Route::get('/user/addresses', [App\user\controller\AddressController::class, 'index']);
    Route::post('/user/addresses', [App\user\controller\AddressController::class, 'store']);
    Route::put('/user/addresses/{id}', [App\user\controller\AddressController::class, 'update']);
    Route::delete('/user/addresses/{id}', [App\user\controller\AddressController::class, 'destroy']);

    // Cart & Orders (read)
    Route::post('/cart', [App\order\controller\OrderController::class, 'addToCart']);
    Route::get('/cart', [App\order\controller\OrderController::class, 'cart']);
    Route::delete('/cart/{id}', [App\order\controller\OrderController::class, 'removeFromCart']);
    Route::put('/cart/{id}', [App\order\controller\OrderController::class, 'updateCartItem']);
    Route::post('/orders', [App\order\controller\OrderController::class, 'store']);
    Route::get('/orders', [App\order\controller\OrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [App\order\controller\OrderController::class, 'show']);
    Route::get('/orders/{id}/payment-methods', [App\payment\controller\PaymentController::class, 'availableChannels']);

    // Resources (read)
    Route::get('/resources', [App\provisioning\controller\ResourceController::class, 'myResources']);
    Route::get('/resources/{id}', [App\provisioning\controller\ResourceController::class, 'show']);
    Route::get('/resources/{id}/status', [App\provisioning\controller\ResourceController::class, 'status']);
    Route::get('/resources/{id}/console', [App\provisioning\controller\ResourceController::class, 'consoleUrl']);

    // DNS
    Route::get('/dns/{domain}', [App\domain\controller\DomainController::class, 'listRecords']);
    Route::post('/dns/{domain}/records', [App\domain\controller\DomainController::class, 'addRecord']);

    // Tickets
    Route::post('/tickets', [App\ticket\controller\TicketController::class, 'create']);
    Route::get('/tickets', [App\ticket\controller\TicketController::class, 'myTickets']);
    Route::get('/tickets/{id}', [App\ticket\controller\TicketController::class, 'show']);
    Route::post('/tickets/{id}/reply', [App\ticket\controller\TicketController::class, 'reply']);

    // Reviews
    Route::post('/products/{productId}/reviews', [App\product\controller\ReviewController::class, 'store']);

    // Invoices
    Route::get('/invoices', [App\order\controller\InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [App\order\controller\InvoiceController::class, 'show']);
    Route::get('/invoices/{id}/download', [App\order\controller\InvoiceController::class, 'download']);

    // Supplier product management
    Route::get('/supplier/products', [App\supplier\controller\SupplierProductController::class, 'index']);
    Route::post('/supplier/products', [App\supplier\controller\SupplierProductController::class, 'store']);
    Route::delete('/supplier/products/{id}', [App\supplier\controller\SupplierProductController::class, 'destroy']);

    // Notification preferences
    Route::get('/user/notification-prefs', [App\notification\controller\NotificationController::class, 'preferences']);
    Route::put('/user/notification-prefs', [App\notification\controller\NotificationController::class, 'updatePreferences']);

    // Supplier
    Route::post('/supplier/apply', [App\supplier\controller\SupplierController::class, 'apply']);
    Route::get('/supplier/settlements', [App\supplier\controller\SupplierController::class, 'settlements']);

    // Supplier ratings
    Route::post('/supplier/ratings', [App\supplier\controller\SupplierRatingController::class, 'store']);
    Route::get('/supplier/ratings/me', [App\supplier\controller\SupplierRatingController::class, 'myRatings']);

    // Logout (revokes current access token via blacklist)
    Route::post('/auth/logout', [App\user\controller\AuthController::class, 'logout']);

})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class, Common\auth\middleware\AuthMiddleware::class]);

// === User sensitive operations (requires password confirmation) ===
Route::group('/api/v1', function () {
    Route::post('/orders/{id}/pay', [App\payment\controller\PaymentController::class, 'pay']);
    Route::post('/supplier/withdraw', [App\supplier\controller\SupplierController::class, 'withdraw']);
    Route::delete('/dns/{domain}/records/{id}', [App\domain\controller\DomainController::class, 'deleteRecord']);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class, Common\auth\middleware\AuthMiddleware::class, Common\confirmation\ConfirmationMiddleware::class]);

// === Supplier external API (API Key auth) ===
Route::group('/api/v1', function () {
    Route::get('/supplier/external/orders', [App\supplier\controller\external\OrderController::class, 'index']);
    Route::get('/supplier/external/orders/{id}', [App\supplier\controller\external\OrderController::class, 'show']);
    Route::get('/supplier/external/resources', [App\supplier\controller\external\ResourceController::class, 'index']);
    Route::get('/supplier/external/resources/{id}/status', [App\supplier\controller\external\ResourceController::class, 'status']);
    Route::get('/supplier/external/settlements', [App\supplier\controller\external\SettlementController::class, 'index']);
    Route::get('/supplier/external/settlements/{id}', [App\supplier\controller\external\SettlementController::class, 'show']);
    Route::post('/supplier/external/withdraw', [App\supplier\controller\external\WithdrawController::class, 'store']);
    Route::get('/supplier/external/withdraws', [App\supplier\controller\external\WithdrawController::class, 'index']);
    Route::get('/supplier/external/products', [App\supplier\controller\external\ProductController::class, 'index']);
    Route::post('/supplier/external/products', [App\supplier\controller\external\ProductController::class, 'store']);
})->middleware([Common\version\middleware\VersionMiddleware::class, Common\auth\middleware\SupplierApiKeyMiddleware::class]);

// === Internal service API (mandatory token; loopback requests are proxied public traffic) ===
Route::group('/internal/v1', function () {
    Route::post('/refunds/execute', [App\order\controller\InternalRefundController::class, 'execute']);
})->middleware([Common\security\InternalTokenRequiredMiddleware::class]);
