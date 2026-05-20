<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

use Webman\Route;

// Health check
Route::get('/health', [App\Controller\HealthController::class, 'index']);

// Auth routes (with encryption + rate limiting + captcha verification on sensitive ops)
Route::group('/api/v1/auth', function () {
    Route::post('/register', [App\User\Controller\AuthController::class, 'register']);
    Route::post('/login', [App\User\Controller\AuthController::class, 'login']);
    Route::post('/refresh', [App\User\Controller\AuthController::class, 'refresh']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class]);

// Password reset (public)
Route::post('/api/v1/auth/forgot-password', [App\User\Controller\AuthController::class, 'forgotPassword']);
Route::post('/api/v1/auth/reset-password', [App\User\Controller\AuthController::class, 'resetPassword']);

// Email verification (public)
Route::get('/api/v1/auth/verify-email', [App\User\Controller\AuthController::class, 'verifyEmail']);

// Service status (public)
Route::get('/api/v1/status', [App\Controller\StatusController::class, 'index']);

// OAuth (public)
Route::get('/api/v1/auth/google', [App\User\Controller\AuthController::class, 'googleOauthRedirect']);
Route::get('/api/v1/auth/google/callback', [App\User\Controller\AuthController::class, 'googleOauthCallback']);
Route::get('/api/v1/auth/apple', [App\User\Controller\AuthController::class, 'appleOauthRedirect']);
Route::get('/api/v1/auth/apple/callback', [App\User\Controller\AuthController::class, 'appleOauthCallback']);

// TOTP recovery login (public)
Route::post('/api/v1/auth/login/recovery', [App\User\Controller\AuthController::class, 'loginWithRecoveryCode']);

// SMS verification (public)
Route::post('/api/v1/auth/send-sms', [App\User\Controller\AuthController::class, 'sendSmsVerify']);

// Captcha route (public, generates click captcha for login/register)
Route::post('/api/v1/captcha/create', [App\Captcha\Controller\CaptchaController::class, 'create'])
    ->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class]);

// Product routes (public)
Route::get('/api/v1/products', [App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/v1/products/search', [App\Product\Controller\ProductController::class, 'search']);
Route::get('/api/v1/products/{id}', [App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/v1/regions', [App\Product\Controller\ProductController::class, 'regions']);
Route::get('/api/v1/products/{productId}/reviews', [App\Product\Controller\ReviewController::class, 'index']);

// Domain routes (public)
Route::get('/api/v1/domain/check/{domain}/{tld}', [App\Domain\Controller\DomainController::class, 'check']);
Route::get('/api/v1/domain/tlds', [App\Domain\Controller\DomainController::class, 'tlds']);

// Help articles (public)
Route::get('/api/v1/help', [App\Controller\HelpController::class, 'index']);
Route::get('/api/v1/help/categories', [App\Controller\HelpController::class, 'categories']);
Route::get('/api/v1/help/{slug}', [App\Controller\HelpController::class, 'show']);

// Payment webhooks (no auth, signature verified)
Route::post('/api/v1/payments/webhook/stripe', [App\Payment\Controller\PaymentController::class, 'stripeWebhook']);

// === User authenticated routes ===
Route::group('/api/v1', function () {
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
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class]);

// === User sensitive operations (requires password confirmation) ===
Route::group('/api/v1', function () {
    Route::post('/orders/{id}/pay', [App\Payment\Controller\PaymentController::class, 'pay']);
    Route::post('/supplier/withdraw', [App\Supplier\Controller\SupplierController::class, 'withdraw']);
    Route::delete('/dns/{domain}/records/{id}', [App\Domain\Controller\DomainController::class, 'deleteRecord']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class, Common\Confirmation\ConfirmationMiddleware::class]);

// === Admin routes ===
Route::group('/admin/api/v1', function () {
    Route::get('/dashboard', [App\Admin\Controller\DashboardController::class, 'index']);

    // Users (read)
    Route::get('/users', [App\Admin\Controller\UserController::class, 'index']);
    Route::get('/users/export', [App\Admin\Controller\UserController::class, 'export']);
    Route::get('/users/{id}', [App\Admin\Controller\UserController::class, 'show']);
    Route::put('/users/{id}/status', [App\Admin\Controller\UserController::class, 'updateStatus']);
    Route::get('/kyc', [App\Admin\Controller\DashboardController::class, 'kycList']);

    // Products (read/write)
    Route::post('/products', [App\Admin\Controller\ProductController::class, 'store']);
    Route::put('/products/{id}', [App\Admin\Controller\ProductController::class, 'update']);
    Route::post('/products/{productId}/skus', [App\Admin\Controller\ProductController::class, 'storeSku']);
    Route::put('/skus/{id}', [App\Admin\Controller\ProductController::class, 'updateSku']);
    Route::post('/skus/{skuId}/region-price', [App\Admin\Controller\ProductController::class, 'setRegionPrice']);

    // Orders (read)
    Route::get('/orders', [App\Admin\Controller\OrderController::class, 'index']);
    Route::get('/orders/export', [App\Admin\Controller\OrderController::class, 'export']);
    Route::get('/orders/{id}', [App\Admin\Controller\OrderController::class, 'show']);

    // Provisioning (read + retry)
    Route::get('/provisioning/tasks', [App\Provisioning\Controller\TaskController::class, 'index']);
    Route::post('/provisioning/tasks/{id}/retry', [App\Provisioning\Controller\TaskController::class, 'retry']);
    Route::post('/provisioning/resources/{id}/upgrade', [App\Provisioning\Controller\ResourceController::class, 'upgrade']);
    Route::get('/provisioning/hosts', [App\Provisioning\Controller\HostController::class, 'index']);

    // Payment
    Route::get('/payments/channels', [App\Admin\Controller\PaymentController::class, 'channels']);
    Route::put('/payments/channels/{id}', [App\Admin\Controller\PaymentController::class, 'updateChannel']);
    Route::get('/payments/transactions', [App\Admin\Controller\PaymentController::class, 'transactions']);
    Route::get('/payments/reconcile', [App\Admin\Controller\PaymentController::class, 'reconcile']);

    // Supplier management (read)
    Route::get('/suppliers', [App\Admin\Controller\SupplierController::class, 'index']);
    Route::get('/suppliers/export', [App\Admin\Controller\SupplierController::class, 'export']);

    // Tickets
    Route::get('/tickets', [App\Ticket\Controller\TicketController::class, 'index']);
    Route::post('/tickets/{id}/assign', [App\Ticket\Controller\TicketController::class, 'assign']);
    Route::post('/tickets/{id}/close', [App\Ticket\Controller\TicketController::class, 'close']);

    // System
    Route::get('/audit-logs', [App\Admin\Controller\SystemController::class, 'auditLogs']);

    // Reports
    Route::get('/reports/revenue', [App\Report\Controller\ReportController::class, 'revenue']);
    Route::get('/reports/supplier', [App\Report\Controller\ReportController::class, 'supplier']);
    Route::get('/reports/region', [App\Report\Controller\ReportController::class, 'byRegion']);

    // Monitoring
    Route::get('/monitor/dashboard', [App\Monitor\Controller\MonitorController::class, 'dashboard']);
    Route::get('/monitor/resources/{id}', [App\Monitor\Controller\MonitorController::class, 'resourceMetrics']);

    // Domain management
    Route::get('/domains/tlds', [App\Admin\Controller\DomainController::class, 'tlds']);
    Route::post('/domains/tlds', [App\Admin\Controller\DomainController::class, 'storeTld']);
    Route::put('/domains/tlds/{id}', [App\Admin\Controller\DomainController::class, 'updateTld']);
    Route::delete('/domains/tlds/{id}', [App\Admin\Controller\DomainController::class, 'deleteTld']);
    Route::get('/domains/zones', [App\Admin\Controller\DomainController::class, 'zones']);

    // Notification management
    Route::get('/notifications/templates', [App\Admin\Controller\NotificationController::class, 'templates']);
    Route::put('/notifications/templates/{id}', [App\Admin\Controller\NotificationController::class, 'updateTemplate']);
    Route::get('/notifications/log', [App\Admin\Controller\NotificationController::class, 'sendLog']);

    // Coupon management
    Route::get('/coupons', [App\Admin\Controller\CouponController::class, 'index']);
    Route::post('/coupons', [App\Admin\Controller\CouponController::class, 'store']);
    Route::delete('/coupons/{id}', [App\Admin\Controller\CouponController::class, 'destroy']);

    // Provider API management
    Route::get('/providers', [App\Admin\Controller\ProviderApiController::class, 'index']);
    Route::post('/providers', [App\Admin\Controller\ProviderApiController::class, 'store']);
    Route::put('/providers/{id}', [App\Admin\Controller\ProviderApiController::class, 'update']);
    Route::delete('/providers/{id}', [App\Admin\Controller\ProviderApiController::class, 'destroy']);

    // Invoice management
    Route::get('/invoices', [App\Admin\Controller\InvoiceController::class, 'index']);
    Route::post('/invoices/{orderId}/generate', [App\Admin\Controller\InvoiceController::class, 'generate']);

    // Supplier API keys
    Route::get('/suppliers/{id}/api-keys', [App\Admin\Controller\SupplierController::class, 'apiKeys']);
    Route::post('/suppliers/{id}/api-keys', [App\Admin\Controller\SupplierController::class, 'createApiKey']);
    Route::delete('/suppliers/api-keys/{id}', [App\Admin\Controller\SupplierController::class, 'revokeApiKey']);

    // Help articles management
    Route::get('/help', [App\Admin\Controller\HelpController::class, 'index']);
    Route::post('/help', [App\Admin\Controller\HelpController::class, 'store']);
    Route::put('/help/{id}', [App\Admin\Controller\HelpController::class, 'update']);
    Route::delete('/help/{id}', [App\Admin\Controller\HelpController::class, 'destroy']);

    // Domain transfers
    Route::get('/domains/transfers', [App\Admin\Controller\DomainController::class, 'transfers']);
    Route::post('/domains/transfers/{id}/approve', [App\Admin\Controller\DomainController::class, 'approveTransfer']);

    // Product import/export
    Route::get('/products/export', [App\Admin\Controller\ImportExportController::class, 'exportProducts']);
    Route::post('/products/import', [App\Admin\Controller\ImportExportController::class, 'importProducts']);

    // Webhook management
    Route::get('/webhooks', [App\Admin\Controller\WebhookController::class, 'index']);
    Route::post('/webhooks', [App\Admin\Controller\WebhookController::class, 'store']);
    Route::delete('/webhooks', [App\Admin\Controller\WebhookController::class, 'destroy']);
    Route::post('/webhooks/test', [App\Admin\Controller\WebhookController::class, 'test']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class, Common\Auth\Middleware\AdminRoleMiddleware::class]);

// === Admin sensitive operations (requires password confirmation) ===
Route::group('/admin/api/v1', function () {
    Route::delete('/products/{id}', [App\Admin\Controller\ProductController::class, 'destroy']);
    Route::post('/orders/{id}/refund', [App\Admin\Controller\OrderController::class, 'refund']);
    Route::post('/provisioning/resources/{id}/destroy', [App\Provisioning\Controller\ResourceController::class, 'destroy']);
    Route::post('/kyc/{id}/approve', [App\Admin\Controller\DashboardController::class, 'kycApprove']);
    Route::post('/kyc/{id}/reject', [App\Admin\Controller\DashboardController::class, 'kycReject']);
    Route::post('/suppliers/{id}/approve', [App\Admin\Controller\SupplierController::class, 'approve']);
    Route::post('/suppliers/{id}/settle', [App\Admin\Controller\SupplierController::class, 'generateSettlement']);
    Route::post('/suppliers/withdraws/{id}/approve', [App\Admin\Controller\SupplierController::class, 'approveWithdraw']);
    Route::put('/system/config', [App\Admin\Controller\SystemController::class, 'updateConfig']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class, Common\Auth\Middleware\AdminRoleMiddleware::class, Common\Confirmation\ConfirmationMiddleware::class]);
