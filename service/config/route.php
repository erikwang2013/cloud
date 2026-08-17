<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

use Webman\Route;
use Common\Version\Middleware\VersionMiddleware;

// Health check (no version check, public)
Route::get('/health', [App\Controller\HealthController::class, 'index']);

// Health check (internal monitoring, token-protected)
Route::group('/health', function () {
    Route::get('/live', [App\Controller\HealthController::class, 'live']);
    Route::get('/ready', [App\Controller\HealthController::class, 'ready']);
    Route::get('/deps', [App\Controller\HealthController::class, 'deps']);
})->middleware([Common\Security\InternalTokenMiddleware::class]);

// Auth routes (with version + encryption + rate limiting + captcha verification on sensitive ops)
Route::group('/api/auth', function () {
    Route::post('/register', [App\User\Controller\AuthController::class, 'register']);
    Route::post('/login', [App\User\Controller\AuthController::class, 'login']);
    Route::post('/refresh', [App\User\Controller\AuthController::class, 'refresh']);
})->middleware([VersionMiddleware::class, Common\Encryption\Middleware\EncryptionMiddleware::class]);

// Password reset (public)
Route::post('/api/auth/forgot-password', [App\User\Controller\AuthController::class, 'forgotPassword']);
Route::post('/api/auth/reset-password', [App\User\Controller\AuthController::class, 'resetPassword']);
// Email verification (public)
Route::get('/api/auth/verify-email', [App\User\Controller\AuthController::class, 'verifyEmail']);

// Service status (public)
Route::get('/api/status', [App\Controller\StatusController::class, 'index']);

// OAuth (public) — generic provider routes: google, apple, facebook, x, microsoft, linkedin, github
// POST callback is required by Apple (response_mode=form_post)
Route::get('/api/auth/{provider}', [App\User\Controller\AuthController::class, 'oauthRedirect']);
Route::get('/api/auth/{provider}/callback', [App\User\Controller\AuthController::class, 'oauthCallback']);
Route::post('/api/auth/{provider}/callback', [App\User\Controller\AuthController::class, 'oauthCallback']);
// TOTP recovery login (public)
Route::post('/api/auth/login/recovery', [App\User\Controller\AuthController::class, 'loginWithRecoveryCode']);
// SMS verification (public)
Route::post('/api/auth/send-sms', [App\User\Controller\AuthController::class, 'sendSmsVerify']);
// Captcha route (public, generates click captcha for login/register)
Route::post('/api/captcha/create', [App\Captcha\Controller\CaptchaController::class, 'create'])
    ->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class]);

// Product routes (public)
Route::get('/api/products', [App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/products/search', [App\Product\Controller\ProductController::class, 'search']);
Route::get('/api/products/{id}', [App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/regions', [App\Product\Controller\ProductController::class, 'regions']);
Route::get('/api/products/{productId}/reviews', [App\Product\Controller\ReviewController::class, 'index']);

// Domain routes (public)
Route::get('/api/domain/check/{domain}/{tld}', [App\Domain\Controller\DomainController::class, 'check']);
Route::get('/api/domain/tlds', [App\Domain\Controller\DomainController::class, 'tlds']);

// Help articles (public)
Route::get('/api/help', [App\Controller\HelpController::class, 'index']);
Route::get('/api/help/categories', [App\Controller\HelpController::class, 'categories']);
Route::get('/api/help/{slug}', [App\Controller\HelpController::class, 'show']);

// SSL plans (public)
Route::get('/api/ssl/plans', [App\Ssl\Controller\SslController::class, 'plans']);

// Supplier ratings (public)
Route::get('/api/suppliers/{supplierId}/ratings', [App\Supplier\Controller\SupplierRatingController::class, 'supplierRatings']);

// GraphQL (public, limited queries)
Route::post('/graphql', [App\Graphql\GraphqlController::class, 'publicHandle']);

// Payment webhooks (no auth, signature verified)
Route::post('/api/payments/webhook/stripe', [App\Payment\Controller\PaymentController::class, 'stripeWebhook']);

// === User authenticated routes ===
Route::group('/api', function () {
    // Profile
    Route::get('/user/profile', [App\User\Controller\ProfileController::class, 'show']);
    Route::put('/user/profile', [App\User\Controller\ProfileController::class, 'update']);
    Route::post('/user/kyc', [App\User\Controller\KycController::class, 'submit']);

    // TOTP 2FA
    Route::post('/user/totp/setup', [App\User\Controller\AuthController::class, 'totpSetup']);
    Route::post('/user/totp/verify', [App\User\Controller\AuthController::class, 'totpVerify']);
    Route::post('/user/totp/disable', [App\User\Controller\AuthController::class, 'totpDisable']);
    Route::get('/user/totp/recovery-codes', [App\User\Controller\AuthController::class, 'totpRecoveryCodes']);

    // Sessions
    Route::get('/user/sessions', [App\User\Controller\AuthController::class, 'sessions']);
    Route::delete('/user/sessions/{id}', [App\User\Controller\AuthController::class, 'revokeSession']);

    // Email verification
    Route::post('/user/resend-verify-email', [App\User\Controller\AuthController::class, 'resendVerifyEmail']);

    // Account
    Route::delete('/user/account', [App\User\Controller\AuthController::class, 'deleteAccount']);

    // Coupon
    Route::post('/coupons/validate', [App\Order\Controller\CouponController::class, 'validate']);

    // Upload
    Route::post('/upload', [App\Controller\UploadController::class, 'upload']);

    // Batch operations
    Route::post('/resources/batch', [App\Provisioning\Controller\BatchController::class, 'batchAction']);
    Route::get('/user/balance', [App\User\Controller\BalanceController::class, 'index']);
    Route::get('/user/balance/transactions', [App\User\Controller\BalanceController::class, 'transactions']);
    Route::get('/user/notifications', [App\Notification\Controller\NotificationController::class, 'index']);
    Route::post('/user/notifications/{id}/read', [App\Notification\Controller\NotificationController::class, 'markRead']);

    // Addresses
    Route::get('/user/addresses', [App\User\Controller\AddressController::class, 'index']);
    Route::post('/user/addresses', [App\User\Controller\AddressController::class, 'store']);
    Route::put('/user/addresses/{id}', [App\User\Controller\AddressController::class, 'update']);
    Route::delete('/user/addresses/{id}', [App\User\Controller\AddressController::class, 'destroy']);

    // Cart & Orders (read)
    Route::post('/cart', [App\Order\Controller\OrderController::class, 'addToCart']);
    Route::get('/cart', [App\Order\Controller\OrderController::class, 'cart']);
    Route::delete('/cart/{id}', [App\Order\Controller\OrderController::class, 'removeFromCart']);
    Route::put('/cart/{id}', [App\Order\Controller\OrderController::class, 'updateCartItem']);
    Route::post('/orders', [App\Order\Controller\OrderController::class, 'store']);
    Route::get('/orders', [App\Order\Controller\OrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [App\Order\Controller\OrderController::class, 'show']);
    Route::get('/orders/{id}/payment-methods', [App\Payment\Controller\PaymentController::class, 'availableChannels']);

    // Resources (read)
    Route::get('/resources', [App\Provisioning\Controller\ResourceController::class, 'myResources']);
    Route::get('/resources/{id}', [App\Provisioning\Controller\ResourceController::class, 'show']);
    Route::get('/resources/{id}/status', [App\Provisioning\Controller\ResourceController::class, 'status']);
    Route::get('/resources/{id}/console', [App\Provisioning\Controller\ResourceController::class, 'consoleUrl']);

    // DNS
    Route::get('/dns/{domain}', [App\Domain\Controller\DomainController::class, 'listRecords']);
    Route::post('/dns/{domain}/records', [App\Domain\Controller\DomainController::class, 'addRecord']);

    // Tickets
    Route::post('/tickets', [App\Ticket\Controller\TicketController::class, 'create']);
    Route::get('/tickets', [App\Ticket\Controller\TicketController::class, 'myTickets']);
    Route::get('/tickets/{id}', [App\Ticket\Controller\TicketController::class, 'show']);
    Route::post('/tickets/{id}/reply', [App\Ticket\Controller\TicketController::class, 'reply']);

    // Reviews
    Route::post('/products/{productId}/reviews', [App\Product\Controller\ReviewController::class, 'store']);

    // Invoices
    Route::get('/invoices', [App\Order\Controller\InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [App\Order\Controller\InvoiceController::class, 'show']);
    Route::get('/invoices/{id}/download', [App\Order\Controller\InvoiceController::class, 'download']);

    // Supplier product management
    Route::get('/supplier/products', [App\Supplier\Controller\SupplierProductController::class, 'index']);
    Route::post('/supplier/products', [App\Supplier\Controller\SupplierProductController::class, 'store']);
    Route::delete('/supplier/products/{id}', [App\Supplier\Controller\SupplierProductController::class, 'destroy']);

    // Notification preferences
    Route::get('/user/notification-prefs', [App\Notification\Controller\NotificationController::class, 'preferences']);
    Route::put('/user/notification-prefs', [App\Notification\Controller\NotificationController::class, 'updatePreferences']);

    // Supplier
    Route::post('/supplier/apply', [App\Supplier\Controller\SupplierController::class, 'apply']);
    Route::get('/supplier/settlements', [App\Supplier\Controller\SupplierController::class, 'settlements']);

    // SSL certificates
    Route::get('/ssl-certs', [App\Ssl\Controller\SslController::class, 'index']);
    Route::get('/ssl-certs/{id}', [App\Ssl\Controller\SslController::class, 'show']);
    Route::get('/ssl-certs/{id}/download', [App\Ssl\Controller\SslController::class, 'downloadCert']);
    Route::post('/ssl-certs/{id}/auto-renew', [App\Ssl\Controller\SslController::class, 'toggleAutoRenew']);

    // Object storage
    Route::get('/storage/buckets', [App\Storage\Controller\StorageController::class, 'index']);
    Route::get('/storage/buckets/{id}', [App\Storage\Controller\StorageController::class, 'show']);
    Route::post('/storage/buckets/{id}/presign-upload', [App\Storage\Controller\StorageController::class, 'presignUpload']);
    Route::post('/storage/buckets/{id}/presign-download', [App\Storage\Controller\StorageController::class, 'presignDownload']);
    Route::get('/storage/buckets/{id}/credentials', [App\Storage\Controller\StorageController::class, 'credentials']);

    // CDN
    Route::get('/cdn/domains', [App\Cdn\Controller\CdnController::class, 'index']);
    Route::get('/cdn/domains/{id}', [App\Cdn\Controller\CdnController::class, 'show']);
    Route::post('/cdn/domains/{id}/purge', [App\Cdn\Controller\CdnController::class, 'purgeCache']);
    Route::get('/cdn/domains/{id}/stats', [App\Cdn\Controller\CdnController::class, 'stats']);

    // Supplier ratings
    Route::post('/supplier/ratings', [App\Supplier\Controller\SupplierRatingController::class, 'store']);
    Route::get('/supplier/ratings/me', [App\Supplier\Controller\SupplierRatingController::class, 'myRatings']);

    // Logout (revokes current access token via blacklist)
    Route::post('/auth/logout', [App\User\Controller\AuthController::class, 'logout']);

    // Affiliate
    Route::get('/affiliate/summary', [App\Affiliate\Controller\AffiliateController::class, 'summary']);
    Route::post('/affiliate/links', [App\Affiliate\Controller\AffiliateController::class, 'generateLink']);
    Route::get('/affiliate/earnings', [App\Affiliate\Controller\AffiliateController::class, 'earnings']);
    Route::post('/affiliate/payout', [App\Affiliate\Controller\AffiliateController::class, 'requestPayout']);

    // GraphQL (authenticated, full queries)
    Route::post('/graphql', [App\Graphql\GraphqlController::class, 'handle']);
})->middleware([VersionMiddleware::class, Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class]);

// === User sensitive operations (requires password confirmation) ===
Route::group('/api', function () {
    Route::post('/orders/{id}/pay', [App\Payment\Controller\PaymentController::class, 'pay']);
    Route::post('/supplier/withdraw', [App\Supplier\Controller\SupplierController::class, 'withdraw']);
    Route::delete('/dns/{domain}/records/{id}', [App\Domain\Controller\DomainController::class, 'deleteRecord']);
})->middleware([VersionMiddleware::class, Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class, Common\Confirmation\ConfirmationMiddleware::class]);

// === Supplier external API (API Key auth) ===
Route::group('/api', function () {
    Route::get('/supplier/external/orders', [App\Supplier\Controller\External\OrderController::class, 'index']);
    Route::get('/supplier/external/orders/{id}', [App\Supplier\Controller\External\OrderController::class, 'show']);
    Route::get('/supplier/external/resources', [App\Supplier\Controller\External\ResourceController::class, 'index']);
    Route::get('/supplier/external/resources/{id}/status', [App\Supplier\Controller\External\ResourceController::class, 'status']);
    Route::get('/supplier/external/settlements', [App\Supplier\Controller\External\SettlementController::class, 'index']);
    Route::get('/supplier/external/settlements/{id}', [App\Supplier\Controller\External\SettlementController::class, 'show']);
    Route::post('/supplier/external/withdraw', [App\Supplier\Controller\External\WithdrawController::class, 'store']);
    Route::get('/supplier/external/withdraws', [App\Supplier\Controller\External\WithdrawController::class, 'index']);
    Route::get('/supplier/external/products', [App\Supplier\Controller\External\ProductController::class, 'index']);
    Route::post('/supplier/external/products', [App\Supplier\Controller\External\ProductController::class, 'store']);
})->middleware([Common\Version\Middleware\VersionMiddleware::class, Common\Auth\Middleware\SupplierApiKeyMiddleware::class]);

// === Admin routes ===
Route::group('/admin/api', function () {
    Route::get('/dashboard', [App\Admin\Controller\DashboardController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('report.view')]);

    // Users (read)
    Route::get('/users', [App\Admin\Controller\UserController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.view')]);
    Route::get('/users/export', [App\Admin\Controller\UserController::class, 'export'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.view')]);
    Route::get('/users/{id}', [App\Admin\Controller\UserController::class, 'show'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.view')]);
    Route::put('/users/{id}/status', [App\Admin\Controller\UserController::class, 'updateStatus'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.update')]);
    Route::get('/kyc', [App\Admin\Controller\DashboardController::class, 'kycList'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.kyc_review')]);

    // Products (read/write)
    Route::post('/products', [App\Admin\Controller\ProductController::class, 'store'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.create')]);
    Route::put('/products/{id}', [App\Admin\Controller\ProductController::class, 'update'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.update')]);
    Route::post('/products/{productId}/skus', [App\Admin\Controller\ProductController::class, 'storeSku'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.create')]);
    Route::put('/skus/{id}', [App\Admin\Controller\ProductController::class, 'updateSku'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.update')]);
    Route::post('/skus/{skuId}/region-price', [App\Admin\Controller\ProductController::class, 'setRegionPrice'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.update')]);

    // Orders (read)
    Route::get('/orders', [App\Admin\Controller\OrderController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.view')]);
    Route::get('/orders/export', [App\Admin\Controller\OrderController::class, 'export'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.view')]);
    Route::get('/orders/{id}', [App\Admin\Controller\OrderController::class, 'show'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.view')]);

    // Provisioning (read + retry)
    Route::get('/provisioning/tasks', [App\Provisioning\Controller\TaskController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.view')]);
    Route::post('/provisioning/tasks/{id}/retry', [App\Provisioning\Controller\TaskController::class, 'retry'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.update')]);
    Route::post('/provisioning/resources/{id}/upgrade', [App\Provisioning\Controller\ResourceController::class, 'upgrade'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.update')]);
    Route::get('/provisioning/hosts', [App\Provisioning\Controller\HostController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.view')]);

    // Payment
    Route::get('/payments/channels', [App\Admin\Controller\PaymentController::class, 'channels'])->middleware([new Common\Auth\Middleware\RbacMiddleware('payment.view')]);
    Route::put('/payments/channels/{id}', [App\Admin\Controller\PaymentController::class, 'updateChannel'])->middleware([new Common\Auth\Middleware\RbacMiddleware('payment.channel_config')]);
    Route::get('/payments/transactions', [App\Admin\Controller\PaymentController::class, 'transactions'])->middleware([new Common\Auth\Middleware\RbacMiddleware('payment.view')]);
    Route::get('/payments/reconcile', [App\Admin\Controller\PaymentController::class, 'reconcile'])->middleware([new Common\Auth\Middleware\RbacMiddleware('payment.reconcile')]);
    Route::post('/payments/reconcile/run', [App\Admin\Controller\PaymentController::class, 'reconcileRun'])->middleware([new Common\Auth\Middleware\RbacMiddleware('payment.reconcile')]);

    // Supplier management (read)
    Route::get('/suppliers', [App\Admin\Controller\SupplierController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.view')]);
    Route::get('/suppliers/export', [App\Admin\Controller\SupplierController::class, 'export'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.view')]);

    // Tickets
    Route::get('/tickets', [App\Ticket\Controller\TicketController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ticket.view')]);
    Route::post('/tickets/{id}/assign', [App\Ticket\Controller\TicketController::class, 'assign'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ticket.assign')]);
    Route::post('/tickets/{id}/close', [App\Ticket\Controller\TicketController::class, 'close'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ticket.view')]);

    // System
    Route::get('/audit-logs', [App\Admin\Controller\SystemController::class, 'auditLogs'])->middleware([new Common\Auth\Middleware\RbacMiddleware('system.config')]);

    // Feature flags
    Route::get('/features', [App\Admin\Controller\SystemController::class, 'features'])->middleware([new Common\Auth\Middleware\RbacMiddleware('system.config')]);
    Route::put('/features/{name}', [App\Admin\Controller\SystemController::class, 'toggleFeature'])->middleware([new Common\Auth\Middleware\RbacMiddleware('system.config')]);

    // Reports
    Route::get('/reports/revenue', [App\Report\Controller\ReportController::class, 'revenue'])->middleware([new Common\Auth\Middleware\RbacMiddleware('report.view')]);
    Route::get('/reports/supplier', [App\Report\Controller\ReportController::class, 'supplier'])->middleware([new Common\Auth\Middleware\RbacMiddleware('report.view')]);
    Route::get('/reports/region', [App\Report\Controller\ReportController::class, 'byRegion'])->middleware([new Common\Auth\Middleware\RbacMiddleware('report.view')]);

    // Monitoring
    Route::get('/monitor/dashboard', [App\Monitor\Controller\MonitorController::class, 'dashboard'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.view')]);
    Route::get('/monitor/resources/{id}', [App\Monitor\Controller\MonitorController::class, 'resourceMetrics'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.view')]);

    // Domain management
    Route::get('/domains/tlds', [App\Admin\Controller\DomainController::class, 'tlds'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.tld')]);
    Route::post('/domains/tlds', [App\Admin\Controller\DomainController::class, 'storeTld'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.tld')]);
    Route::put('/domains/tlds/{id}', [App\Admin\Controller\DomainController::class, 'updateTld'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.tld')]);
    Route::delete('/domains/tlds/{id}', [App\Admin\Controller\DomainController::class, 'deleteTld'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.tld')]);
    Route::get('/domains/zones', [App\Admin\Controller\DomainController::class, 'zones'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.tld')]);

    // Notification management
    Route::get('/notifications/templates', [App\Admin\Controller\NotificationController::class, 'templates'])->middleware([new Common\Auth\Middleware\RbacMiddleware('notification.template')]);
    Route::put('/notifications/templates/{id}', [App\Admin\Controller\NotificationController::class, 'updateTemplate'])->middleware([new Common\Auth\Middleware\RbacMiddleware('notification.template')]);
    Route::get('/notifications/log', [App\Admin\Controller\NotificationController::class, 'sendLog'])->middleware([new Common\Auth\Middleware\RbacMiddleware('notification.send')]);

    // Coupon management
    Route::get('/coupons', [App\Admin\Controller\CouponController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.view')]);
    Route::post('/coupons', [App\Admin\Controller\CouponController::class, 'store'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.update')]);
    Route::delete('/coupons/{id}', [App\Admin\Controller\CouponController::class, 'destroy'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.update')]);

    // Provider API management
    Route::get('/providers', [App\Admin\Controller\ProviderApiController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('provider.config')]);
    Route::post('/providers', [App\Admin\Controller\ProviderApiController::class, 'store'])->middleware([new Common\Auth\Middleware\RbacMiddleware('provider.config')]);
    Route::put('/providers/{id}', [App\Admin\Controller\ProviderApiController::class, 'update'])->middleware([new Common\Auth\Middleware\RbacMiddleware('provider.config')]);
    Route::delete('/providers/{id}', [App\Admin\Controller\ProviderApiController::class, 'destroy'])->middleware([new Common\Auth\Middleware\RbacMiddleware('provider.config')]);

    // Invoice management
    Route::get('/invoices', [App\Admin\Controller\InvoiceController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.view')]);
    Route::post('/invoices/{orderId}/generate', [App\Admin\Controller\InvoiceController::class, 'generate'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.update')]);

    // Supplier API keys
    Route::get('/suppliers/{id}/api-keys', [App\Admin\Controller\SupplierController::class, 'apiKeys'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.review')]);
    Route::post('/suppliers/{id}/api-keys', [App\Admin\Controller\SupplierController::class, 'createApiKey'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.review')]);
    Route::delete('/suppliers/api-keys/{id}', [App\Admin\Controller\SupplierController::class, 'revokeApiKey'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.review')]);

    // Help articles management
    Route::get('/help', [App\Admin\Controller\HelpController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('help.manage')]);
    Route::post('/help', [App\Admin\Controller\HelpController::class, 'store'])->middleware([new Common\Auth\Middleware\RbacMiddleware('help.manage')]);
    Route::put('/help/{id}', [App\Admin\Controller\HelpController::class, 'update'])->middleware([new Common\Auth\Middleware\RbacMiddleware('help.manage')]);
    Route::delete('/help/{id}', [App\Admin\Controller\HelpController::class, 'destroy'])->middleware([new Common\Auth\Middleware\RbacMiddleware('help.manage')]);

    // Domain transfers
    Route::get('/domains/transfers', [App\Admin\Controller\DomainController::class, 'transfers'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.tld')]);
    Route::post('/domains/transfers/{id}/approve', [App\Admin\Controller\DomainController::class, 'approveTransfer'])->middleware([new Common\Auth\Middleware\RbacMiddleware('domain.transfer_approve')]);

    // Product import/export
    Route::get('/products/export', [App\Admin\Controller\ImportExportController::class, 'exportProducts'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.update')]);
    Route::post('/products/import', [App\Admin\Controller\ImportExportController::class, 'importProducts'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.create')]);

    // Webhook management
    Route::get('/webhooks', [App\Admin\Controller\WebhookController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('webhook.manage')]);
    Route::post('/webhooks', [App\Admin\Controller\WebhookController::class, 'store'])->middleware([new Common\Auth\Middleware\RbacMiddleware('webhook.manage')]);
    Route::delete('/webhooks', [App\Admin\Controller\WebhookController::class, 'destroy'])->middleware([new Common\Auth\Middleware\RbacMiddleware('webhook.manage')]);
    Route::post('/webhooks/test', [App\Admin\Controller\WebhookController::class, 'test'])->middleware([new Common\Auth\Middleware\RbacMiddleware('webhook.manage')]);

    // SSL certificate management
    Route::get('/ssl/plans', [App\Admin\Controller\SslController::class, 'plans'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ssl.plan')]);
    Route::post('/ssl/plans', [App\Admin\Controller\SslController::class, 'storePlan'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ssl.plan')]);
    Route::put('/ssl/plans/{id}', [App\Admin\Controller\SslController::class, 'updatePlan'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ssl.plan')]);
    Route::delete('/ssl/plans/{id}', [App\Admin\Controller\SslController::class, 'destroyPlan'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ssl.plan')]);
    Route::get('/ssl/certs', [App\Admin\Controller\SslController::class, 'certs'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ssl.plan')]);
    Route::post('/ssl/certs/{id}/revoke', [App\Admin\Controller\SslController::class, 'revokeCert'])->middleware([new Common\Auth\Middleware\RbacMiddleware('ssl.plan')]);

    // Usage billing management
    Route::get('/billing/rates', [App\Admin\Controller\BillingController::class, 'rates'])->middleware([new Common\Auth\Middleware\RbacMiddleware('billing.rate')]);
    Route::post('/billing/rates', [App\Admin\Controller\BillingController::class, 'storeRate'])->middleware([new Common\Auth\Middleware\RbacMiddleware('billing.rate')]);
    Route::put('/billing/rates/{id}', [App\Admin\Controller\BillingController::class, 'updateRate'])->middleware([new Common\Auth\Middleware\RbacMiddleware('billing.rate')]);
    Route::delete('/billing/rates/{id}', [App\Admin\Controller\BillingController::class, 'destroyRate'])->middleware([new Common\Auth\Middleware\RbacMiddleware('billing.rate')]);
    Route::get('/billing/usage', [App\Admin\Controller\BillingController::class, 'usage'])->middleware([new Common\Auth\Middleware\RbacMiddleware('billing.rate')]);

    // CDN management
    Route::get('/cdn/domains', [App\Admin\Controller\CdnController::class, 'index'])->middleware([new Common\Auth\Middleware\RbacMiddleware('cdn.manage')]);
    Route::put('/cdn/domains/{id}', [App\Admin\Controller\CdnController::class, 'updatePlan'])->middleware([new Common\Auth\Middleware\RbacMiddleware('cdn.manage')]);

    // Supplier rating moderation
    Route::get('/suppliers/{id}/ratings', [App\Admin\Controller\RatingController::class, 'supplierRatings'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.view')]);
    Route::post('/suppliers/ratings/{id}/approve', [App\Admin\Controller\RatingController::class, 'approve'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.review')]);
    Route::post('/suppliers/ratings/{id}/hide', [App\Admin\Controller\RatingController::class, 'hide'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.review')]);

    // Affiliate management
    Route::get('/affiliate/plans', [App\Admin\Controller\AffiliateController::class, 'plans'])->middleware([new Common\Auth\Middleware\RbacMiddleware('affiliate.approve')]);
    Route::post('/affiliate/plans', [App\Admin\Controller\AffiliateController::class, 'storePlan'])->middleware([new Common\Auth\Middleware\RbacMiddleware('affiliate.approve')]);
    Route::get('/affiliate/earnings', [App\Admin\Controller\AffiliateController::class, 'earnings'])->middleware([new Common\Auth\Middleware\RbacMiddleware('affiliate.approve')]);
    Route::post('/affiliate/earnings/{id}/approve', [App\Admin\Controller\AffiliateController::class, 'approveEarning'])->middleware([new Common\Auth\Middleware\RbacMiddleware('affiliate.approve')]);
    Route::get('/affiliate/payouts', [App\Admin\Controller\AffiliateController::class, 'payouts'])->middleware([new Common\Auth\Middleware\RbacMiddleware('affiliate.approve')]);
    Route::post('/affiliate/payouts/{id}/approve', [App\Admin\Controller\AffiliateController::class, 'approvePayout'])->middleware([new Common\Auth\Middleware\RbacMiddleware('affiliate.approve')]);
})->middleware([VersionMiddleware::class, Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class, Common\Auth\Middleware\AdminRoleMiddleware::class]);

// === Admin sensitive operations (requires password confirmation) ===
Route::group('/admin/api', function () {
    Route::delete('/products/{id}', [App\Admin\Controller\ProductController::class, 'destroy'])->middleware([new Common\Auth\Middleware\RbacMiddleware('product.delete')]);
    Route::post('/orders/{id}/refund', [App\Admin\Controller\OrderController::class, 'refund'])->middleware([new Common\Auth\Middleware\RbacMiddleware('order.refund')]);
    Route::post('/provisioning/resources/{id}/destroy', [App\Provisioning\Controller\ResourceController::class, 'destroy'])->middleware([new Common\Auth\Middleware\RbacMiddleware('resource.destroy')]);
    Route::post('/kyc/{id}/approve', [App\Admin\Controller\DashboardController::class, 'kycApprove'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.kyc_review')]);
    Route::post('/kyc/{id}/reject', [App\Admin\Controller\DashboardController::class, 'kycReject'])->middleware([new Common\Auth\Middleware\RbacMiddleware('user.kyc_review')]);
    Route::post('/suppliers/{id}/approve', [App\Admin\Controller\SupplierController::class, 'approve'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.review')]);
    Route::post('/suppliers/{id}/settle', [App\Admin\Controller\SupplierController::class, 'generateSettlement'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.settle')]);
    Route::post('/suppliers/withdraws/{id}/approve', [App\Admin\Controller\SupplierController::class, 'approveWithdraw'])->middleware([new Common\Auth\Middleware\RbacMiddleware('supplier.withdraw_review')]);
    Route::put('/system/config', [App\Admin\Controller\SystemController::class, 'updateConfig'])->middleware([new Common\Auth\Middleware\RbacMiddleware('system.config')]);
})->middleware([VersionMiddleware::class, Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class, Common\Auth\Middleware\AdminRoleMiddleware::class, Common\Confirmation\ConfirmationMiddleware::class]);
