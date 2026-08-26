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

// Health check (internal monitoring, token-protected)
Route::group('/health', function () {
    Route::get('/live', [App\controller\HealthController::class, 'live']);
    Route::get('/ready', [App\controller\HealthController::class, 'ready']);
    Route::get('/deps', [App\controller\HealthController::class, 'deps']);
})->middleware([Common\security\InternalTokenMiddleware::class]);

// Auth routes (with version + encryption + rate limiting + captcha verification on sensitive ops)
Route::group('/api/auth', function () {
    Route::post('/register', [App\user\controller\AuthController::class, 'register']);
    Route::post('/login', [App\user\controller\AuthController::class, 'login']);
    Route::post('/refresh', [App\user\controller\AuthController::class, 'refresh']);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class]);

// Password reset (public)
Route::post('/api/auth/forgot-password', [App\user\controller\AuthController::class, 'forgotPassword']);
Route::post('/api/auth/reset-password', [App\user\controller\AuthController::class, 'resetPassword']);
// Email verification (public)
Route::get('/api/auth/verify-email', [App\user\controller\AuthController::class, 'verifyEmail']);

// Service status (public)
Route::get('/api/status', [App\controller\StatusController::class, 'index']);

// OAuth (public) — generic provider routes: google, apple, facebook, x, microsoft, linkedin, github
// POST callback is required by Apple (response_mode=form_post)
Route::get('/api/auth/{provider}', [App\user\controller\AuthController::class, 'oauthRedirect']);
Route::get('/api/auth/{provider}/callback', [App\user\controller\AuthController::class, 'oauthCallback']);
Route::post('/api/auth/{provider}/callback', [App\user\controller\AuthController::class, 'oauthCallback']);
// TOTP recovery login (public)
Route::post('/api/auth/login/recovery', [App\user\controller\AuthController::class, 'loginWithRecoveryCode']);
// SMS verification (public)
Route::post('/api/auth/send-sms', [App\user\controller\AuthController::class, 'sendSmsVerify']);
// Captcha route (public, generates click captcha for login/register)
Route::post('/api/captcha/create', [App\captcha\controller\CaptchaController::class, 'create'])
    ->middleware([Common\encryption\middleware\EncryptionMiddleware::class]);

// Product routes (public)
Route::get('/api/products', [App\product\controller\ProductController::class, 'index']);
Route::get('/api/products/search', [App\product\controller\ProductController::class, 'search']);
Route::get('/api/products/{id}', [App\product\controller\ProductController::class, 'show']);
Route::get('/api/regions', [App\product\controller\ProductController::class, 'regions']);
Route::get('/api/products/{productId}/reviews', [App\product\controller\ReviewController::class, 'index']);

// Domain routes (public)
Route::get('/api/domain/check/{domain}/{tld}', [App\domain\controller\DomainController::class, 'check']);
Route::get('/api/domain/tlds', [App\domain\controller\DomainController::class, 'tlds']);

// Help articles (public)
Route::get('/api/help', [App\controller\HelpController::class, 'index']);
Route::get('/api/help/categories', [App\controller\HelpController::class, 'categories']);
Route::get('/api/help/{slug}', [App\controller\HelpController::class, 'show']);

// SSL plans (public)
Route::get('/api/ssl/plans', [App\ssl\controller\SslController::class, 'plans']);

// Supplier ratings (public)
Route::get('/api/suppliers/{supplierId}/ratings', [App\supplier\controller\SupplierRatingController::class, 'supplierRatings']);

// GraphQL (public, limited queries)
Route::post('/graphql', [App\graphql\GraphqlController::class, 'publicHandle']);

// Payment webhooks (no auth, signature verified)
Route::post('/api/payments/webhook/stripe', [App\payment\controller\PaymentController::class, 'stripeWebhook']);

// === User authenticated routes ===
Route::group('/api', function () {
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

    // SSL certificates
    Route::get('/ssl-certs', [App\ssl\controller\SslController::class, 'index']);
    Route::get('/ssl-certs/{id}', [App\ssl\controller\SslController::class, 'show']);
    Route::get('/ssl-certs/{id}/download', [App\ssl\controller\SslController::class, 'downloadCert']);
    Route::post('/ssl-certs/{id}/auto-renew', [App\ssl\controller\SslController::class, 'toggleAutoRenew']);

    // Object storage
    Route::get('/storage/buckets', [App\storage\controller\StorageController::class, 'index']);
    Route::get('/storage/buckets/{id}', [App\storage\controller\StorageController::class, 'show']);
    Route::post('/storage/buckets/{id}/presign-upload', [App\storage\controller\StorageController::class, 'presignUpload']);
    Route::post('/storage/buckets/{id}/presign-download', [App\storage\controller\StorageController::class, 'presignDownload']);
    Route::get('/storage/buckets/{id}/credentials', [App\storage\controller\StorageController::class, 'credentials']);

    // CDN
    Route::get('/cdn/domains', [App\cdn\controller\CdnController::class, 'index']);
    Route::get('/cdn/domains/{id}', [App\cdn\controller\CdnController::class, 'show']);
    Route::post('/cdn/domains/{id}/purge', [App\cdn\controller\CdnController::class, 'purgeCache']);
    Route::get('/cdn/domains/{id}/stats', [App\cdn\controller\CdnController::class, 'stats']);

    // Supplier ratings
    Route::post('/supplier/ratings', [App\supplier\controller\SupplierRatingController::class, 'store']);
    Route::get('/supplier/ratings/me', [App\supplier\controller\SupplierRatingController::class, 'myRatings']);

    // Logout (revokes current access token via blacklist)
    Route::post('/auth/logout', [App\user\controller\AuthController::class, 'logout']);

    // Affiliate
    Route::get('/affiliate/summary', [App\affiliate\controller\AffiliateController::class, 'summary']);
    Route::post('/affiliate/links', [App\affiliate\controller\AffiliateController::class, 'generateLink']);
    Route::get('/affiliate/earnings', [App\affiliate\controller\AffiliateController::class, 'earnings']);
    Route::post('/affiliate/payout', [App\affiliate\controller\AffiliateController::class, 'requestPayout']);

    // GraphQL (authenticated, full queries)
    Route::post('/graphql', [App\graphql\GraphqlController::class, 'handle']);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class, Common\auth\middleware\AuthMiddleware::class]);

// === User sensitive operations (requires password confirmation) ===
Route::group('/api', function () {
    Route::post('/orders/{id}/pay', [App\payment\controller\PaymentController::class, 'pay']);
    Route::post('/supplier/withdraw', [App\supplier\controller\SupplierController::class, 'withdraw']);
    Route::delete('/dns/{domain}/records/{id}', [App\domain\controller\DomainController::class, 'deleteRecord']);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class, Common\auth\middleware\AuthMiddleware::class, Common\confirmation\ConfirmationMiddleware::class]);

// === Supplier external API (API Key auth) ===
Route::group('/api', function () {
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

// === Admin routes ===
Route::group('/admin/api', function () {
    Route::get('/dashboard', [App\admin\controller\DashboardController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('report.view')]);

    // Users (read)
    Route::get('/users', [App\admin\controller\UserController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('user.view')]);
    Route::get('/users/export', [App\admin\controller\UserController::class, 'export'])->middleware([new Common\auth\middleware\RbacMiddleware('user.view')]);
    Route::get('/users/{id}', [App\admin\controller\UserController::class, 'show'])->middleware([new Common\auth\middleware\RbacMiddleware('user.view')]);
    Route::put('/users/{id}/status', [App\admin\controller\UserController::class, 'updateStatus'])->middleware([new Common\auth\middleware\RbacMiddleware('user.update')]);
    Route::get('/kyc', [App\admin\controller\DashboardController::class, 'kycList'])->middleware([new Common\auth\middleware\RbacMiddleware('user.kyc_review')]);

    // Products (read/write)
    Route::post('/products', [App\admin\controller\ProductController::class, 'store'])->middleware([new Common\auth\middleware\RbacMiddleware('product.create')]);
    Route::put('/products/{id}', [App\admin\controller\ProductController::class, 'update'])->middleware([new Common\auth\middleware\RbacMiddleware('product.update')]);
    Route::post('/products/{productId}/skus', [App\admin\controller\ProductController::class, 'storeSku'])->middleware([new Common\auth\middleware\RbacMiddleware('product.create')]);
    Route::put('/skus/{id}', [App\admin\controller\ProductController::class, 'updateSku'])->middleware([new Common\auth\middleware\RbacMiddleware('product.update')]);
    Route::post('/skus/{skuId}/region-price', [App\admin\controller\ProductController::class, 'setRegionPrice'])->middleware([new Common\auth\middleware\RbacMiddleware('product.update')]);

    // Orders (read)
    Route::get('/orders', [App\admin\controller\OrderController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('order.view')]);
    Route::get('/orders/export', [App\admin\controller\OrderController::class, 'export'])->middleware([new Common\auth\middleware\RbacMiddleware('order.view')]);
    Route::get('/orders/{id}', [App\admin\controller\OrderController::class, 'show'])->middleware([new Common\auth\middleware\RbacMiddleware('order.view')]);

    // Provisioning (read + retry)
    Route::get('/provisioning/tasks', [App\provisioning\controller\TaskController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.view')]);
    Route::post('/provisioning/tasks/{id}/retry', [App\provisioning\controller\TaskController::class, 'retry'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.update')]);
    Route::post('/provisioning/resources/{id}/upgrade', [App\provisioning\controller\ResourceController::class, 'upgrade'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.update')]);
    Route::get('/provisioning/hosts', [App\provisioning\controller\HostController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.view')]);

    // Payment
    Route::get('/payments/channels', [App\admin\controller\PaymentController::class, 'channels'])->middleware([new Common\auth\middleware\RbacMiddleware('payment.view')]);
    Route::put('/payments/channels/{id}', [App\admin\controller\PaymentController::class, 'updateChannel'])->middleware([new Common\auth\middleware\RbacMiddleware('payment.channel_config')]);
    Route::get('/payments/transactions', [App\admin\controller\PaymentController::class, 'transactions'])->middleware([new Common\auth\middleware\RbacMiddleware('payment.view')]);
    Route::get('/payments/reconcile', [App\admin\controller\PaymentController::class, 'reconcile'])->middleware([new Common\auth\middleware\RbacMiddleware('payment.reconcile')]);
    Route::post('/payments/reconcile/run', [App\admin\controller\PaymentController::class, 'reconcileRun'])->middleware([new Common\auth\middleware\RbacMiddleware('payment.reconcile')]);

    // Supplier management (read)
    Route::get('/suppliers', [App\admin\controller\SupplierController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.view')]);
    Route::get('/suppliers/export', [App\admin\controller\SupplierController::class, 'export'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.view')]);

    // Tickets
    Route::get('/tickets', [App\ticket\controller\TicketController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('ticket.view')]);
    Route::post('/tickets/{id}/assign', [App\ticket\controller\TicketController::class, 'assign'])->middleware([new Common\auth\middleware\RbacMiddleware('ticket.assign')]);
    Route::post('/tickets/{id}/close', [App\ticket\controller\TicketController::class, 'close'])->middleware([new Common\auth\middleware\RbacMiddleware('ticket.view')]);

    // System
    Route::get('/audit-logs', [App\admin\controller\SystemController::class, 'auditLogs'])->middleware([new Common\auth\middleware\RbacMiddleware('system.config')]);

    // Feature flags
    Route::get('/features', [App\admin\controller\SystemController::class, 'features'])->middleware([new Common\auth\middleware\RbacMiddleware('system.config')]);
    Route::put('/features/{name}', [App\admin\controller\SystemController::class, 'toggleFeature'])->middleware([new Common\auth\middleware\RbacMiddleware('system.config')]);

    // Reports
    Route::get('/reports/revenue', [App\report\controller\ReportController::class, 'revenue'])->middleware([new Common\auth\middleware\RbacMiddleware('report.view')]);
    Route::get('/reports/supplier', [App\report\controller\ReportController::class, 'supplier'])->middleware([new Common\auth\middleware\RbacMiddleware('report.view')]);
    Route::get('/reports/region', [App\report\controller\ReportController::class, 'byRegion'])->middleware([new Common\auth\middleware\RbacMiddleware('report.view')]);

    // Monitoring
    Route::get('/monitor/dashboard', [App\monitor\controller\MonitorController::class, 'dashboard'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.view')]);
    Route::get('/monitor/resources/{id}', [App\monitor\controller\MonitorController::class, 'resourceMetrics'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.view')]);

    // Domain management
    Route::get('/domains/tlds', [App\admin\controller\DomainController::class, 'tlds'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.tld')]);
    Route::post('/domains/tlds', [App\admin\controller\DomainController::class, 'storeTld'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.tld')]);
    Route::put('/domains/tlds/{id}', [App\admin\controller\DomainController::class, 'updateTld'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.tld')]);
    Route::delete('/domains/tlds/{id}', [App\admin\controller\DomainController::class, 'deleteTld'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.tld')]);
    Route::get('/domains/zones', [App\admin\controller\DomainController::class, 'zones'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.tld')]);

    // Notification management
    Route::get('/notifications/templates', [App\admin\controller\NotificationController::class, 'templates'])->middleware([new Common\auth\middleware\RbacMiddleware('notification.template')]);
    Route::put('/notifications/templates/{id}', [App\admin\controller\NotificationController::class, 'updateTemplate'])->middleware([new Common\auth\middleware\RbacMiddleware('notification.template')]);
    Route::get('/notifications/log', [App\admin\controller\NotificationController::class, 'sendLog'])->middleware([new Common\auth\middleware\RbacMiddleware('notification.send')]);

    // Coupon management
    Route::get('/coupons', [App\admin\controller\CouponController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('coupon.manage')]);
    Route::post('/coupons', [App\admin\controller\CouponController::class, 'store'])->middleware([new Common\auth\middleware\RbacMiddleware('coupon.manage')]);
    Route::delete('/coupons/{id}', [App\admin\controller\CouponController::class, 'destroy'])->middleware([new Common\auth\middleware\RbacMiddleware('coupon.manage')]);

    // Provider API management
    Route::get('/providers', [App\admin\controller\ProviderApiController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('provider.config')]);
    Route::post('/providers', [App\admin\controller\ProviderApiController::class, 'store'])->middleware([new Common\auth\middleware\RbacMiddleware('provider.config')]);
    Route::put('/providers/{id}', [App\admin\controller\ProviderApiController::class, 'update'])->middleware([new Common\auth\middleware\RbacMiddleware('provider.config')]);
    Route::delete('/providers/{id}', [App\admin\controller\ProviderApiController::class, 'destroy'])->middleware([new Common\auth\middleware\RbacMiddleware('provider.config')]);

    // Invoice management
    Route::get('/invoices', [App\admin\controller\InvoiceController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('order.view')]);
    Route::post('/invoices/{orderId}/generate', [App\admin\controller\InvoiceController::class, 'generate'])->middleware([new Common\auth\middleware\RbacMiddleware('order.update')]);

    // Supplier API keys
    Route::get('/suppliers/{id}/api-keys', [App\admin\controller\SupplierController::class, 'apiKeys'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.review')]);
    Route::post('/suppliers/{id}/api-keys', [App\admin\controller\SupplierController::class, 'createApiKey'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.review')]);
    Route::delete('/suppliers/api-keys/{id}', [App\admin\controller\SupplierController::class, 'revokeApiKey'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.review')]);

    // Help articles management
    Route::get('/help', [App\admin\controller\HelpController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('help.manage')]);
    Route::post('/help', [App\admin\controller\HelpController::class, 'store'])->middleware([new Common\auth\middleware\RbacMiddleware('help.manage')]);
    Route::put('/help/{id}', [App\admin\controller\HelpController::class, 'update'])->middleware([new Common\auth\middleware\RbacMiddleware('help.manage')]);
    Route::delete('/help/{id}', [App\admin\controller\HelpController::class, 'destroy'])->middleware([new Common\auth\middleware\RbacMiddleware('help.manage')]);

    // Domain transfers
    Route::get('/domains/transfers', [App\admin\controller\DomainController::class, 'transfers'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.tld')]);
    Route::post('/domains/transfers/{id}/approve', [App\admin\controller\DomainController::class, 'approveTransfer'])->middleware([new Common\auth\middleware\RbacMiddleware('domain.transfer_approve')]);

    // Product import/export
    Route::get('/products/export', [App\admin\controller\ImportExportController::class, 'exportProducts'])->middleware([new Common\auth\middleware\RbacMiddleware('product.update')]);
    Route::post('/products/import', [App\admin\controller\ImportExportController::class, 'importProducts'])->middleware([new Common\auth\middleware\RbacMiddleware('product.create')]);

    // Webhook management
    Route::get('/webhooks', [App\admin\controller\WebhookController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('webhook.manage')]);
    Route::post('/webhooks', [App\admin\controller\WebhookController::class, 'store'])->middleware([new Common\auth\middleware\RbacMiddleware('webhook.manage')]);
    Route::delete('/webhooks', [App\admin\controller\WebhookController::class, 'destroy'])->middleware([new Common\auth\middleware\RbacMiddleware('webhook.manage')]);
    Route::post('/webhooks/test', [App\admin\controller\WebhookController::class, 'test'])->middleware([new Common\auth\middleware\RbacMiddleware('webhook.manage')]);

    // SSL certificate management
    Route::get('/ssl/plans', [App\admin\controller\SslController::class, 'plans'])->middleware([new Common\auth\middleware\RbacMiddleware('ssl.plan')]);
    Route::post('/ssl/plans', [App\admin\controller\SslController::class, 'storePlan'])->middleware([new Common\auth\middleware\RbacMiddleware('ssl.plan')]);
    Route::put('/ssl/plans/{id}', [App\admin\controller\SslController::class, 'updatePlan'])->middleware([new Common\auth\middleware\RbacMiddleware('ssl.plan')]);
    Route::delete('/ssl/plans/{id}', [App\admin\controller\SslController::class, 'destroyPlan'])->middleware([new Common\auth\middleware\RbacMiddleware('ssl.plan')]);
    Route::get('/ssl/certs', [App\admin\controller\SslController::class, 'certs'])->middleware([new Common\auth\middleware\RbacMiddleware('ssl.plan')]);
    Route::post('/ssl/certs/{id}/revoke', [App\admin\controller\SslController::class, 'revokeCert'])->middleware([new Common\auth\middleware\RbacMiddleware('ssl.plan')]);

    // Usage billing management
    Route::get('/billing/rates', [App\admin\controller\BillingController::class, 'rates'])->middleware([new Common\auth\middleware\RbacMiddleware('billing.rate')]);
    Route::post('/billing/rates', [App\admin\controller\BillingController::class, 'storeRate'])->middleware([new Common\auth\middleware\RbacMiddleware('billing.rate')]);
    Route::put('/billing/rates/{id}', [App\admin\controller\BillingController::class, 'updateRate'])->middleware([new Common\auth\middleware\RbacMiddleware('billing.rate')]);
    Route::delete('/billing/rates/{id}', [App\admin\controller\BillingController::class, 'destroyRate'])->middleware([new Common\auth\middleware\RbacMiddleware('billing.rate')]);
    Route::get('/billing/usage', [App\admin\controller\BillingController::class, 'usage'])->middleware([new Common\auth\middleware\RbacMiddleware('billing.rate')]);

    // CDN management
    Route::get('/cdn/domains', [App\admin\controller\CdnController::class, 'index'])->middleware([new Common\auth\middleware\RbacMiddleware('cdn.manage')]);
    Route::put('/cdn/domains/{id}', [App\admin\controller\CdnController::class, 'updatePlan'])->middleware([new Common\auth\middleware\RbacMiddleware('cdn.manage')]);

    // Supplier rating moderation
    Route::get('/suppliers/{id}/ratings', [App\admin\controller\RatingController::class, 'supplierRatings'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.view')]);
    Route::post('/suppliers/ratings/{id}/approve', [App\admin\controller\RatingController::class, 'approve'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.review')]);
    Route::post('/suppliers/ratings/{id}/hide', [App\admin\controller\RatingController::class, 'hide'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.review')]);

    // Affiliate management
    Route::get('/affiliate/plans', [App\admin\controller\AffiliateController::class, 'plans'])->middleware([new Common\auth\middleware\RbacMiddleware('affiliate.approve')]);
    Route::post('/affiliate/plans', [App\admin\controller\AffiliateController::class, 'storePlan'])->middleware([new Common\auth\middleware\RbacMiddleware('affiliate.approve')]);
    Route::get('/affiliate/earnings', [App\admin\controller\AffiliateController::class, 'earnings'])->middleware([new Common\auth\middleware\RbacMiddleware('affiliate.approve')]);
    Route::post('/affiliate/earnings/{id}/approve', [App\admin\controller\AffiliateController::class, 'approveEarning'])->middleware([new Common\auth\middleware\RbacMiddleware('affiliate.approve')]);
    Route::get('/affiliate/payouts', [App\admin\controller\AffiliateController::class, 'payouts'])->middleware([new Common\auth\middleware\RbacMiddleware('affiliate.approve')]);
    Route::post('/affiliate/payouts/{id}/approve', [App\admin\controller\AffiliateController::class, 'approvePayout'])->middleware([new Common\auth\middleware\RbacMiddleware('affiliate.approve')]);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class, Common\auth\middleware\AuthMiddleware::class, Common\auth\middleware\AdminRoleMiddleware::class]);

// === Admin sensitive operations (requires password confirmation) ===
Route::group('/admin/api', function () {
    Route::delete('/products/{id}', [App\admin\controller\ProductController::class, 'destroy'])->middleware([new Common\auth\middleware\RbacMiddleware('product.delete')]);
    Route::post('/orders/{id}/refund', [App\admin\controller\OrderController::class, 'refund'])->middleware([new Common\auth\middleware\RbacMiddleware('order.refund')]);
    Route::post('/provisioning/resources/{id}/destroy', [App\provisioning\controller\ResourceController::class, 'destroy'])->middleware([new Common\auth\middleware\RbacMiddleware('resource.destroy')]);
    Route::post('/kyc/{id}/approve', [App\admin\controller\DashboardController::class, 'kycApprove'])->middleware([new Common\auth\middleware\RbacMiddleware('user.kyc_review')]);
    Route::post('/kyc/{id}/reject', [App\admin\controller\DashboardController::class, 'kycReject'])->middleware([new Common\auth\middleware\RbacMiddleware('user.kyc_review')]);
    Route::post('/suppliers/{id}/approve', [App\admin\controller\SupplierController::class, 'approve'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.review')]);
    Route::post('/suppliers/{id}/settle', [App\admin\controller\SupplierController::class, 'generateSettlement'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.settle')]);
    Route::post('/suppliers/withdraws/{id}/approve', [App\admin\controller\SupplierController::class, 'approveWithdraw'])->middleware([new Common\auth\middleware\RbacMiddleware('supplier.withdraw_review')]);
    Route::put('/system/config', [App\admin\controller\SystemController::class, 'updateConfig'])->middleware([new Common\auth\middleware\RbacMiddleware('system.config')]);
})->middleware([VersionMiddleware::class, Common\encryption\middleware\EncryptionMiddleware::class, Common\auth\middleware\AuthMiddleware::class, Common\auth\middleware\AdminRoleMiddleware::class, new Common\confirmation\ConfirmationMiddleware(true)]);
