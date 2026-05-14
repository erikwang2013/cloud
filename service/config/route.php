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

// Auth routes (with encryption + rate limiting)
Route::group('/api/v1/auth', function () {
    Route::post('/register', [App\User\Controller\AuthController::class, 'register']);
    Route::post('/login', [App\User\Controller\AuthController::class, 'login']);
    Route::post('/refresh', [App\User\Controller\AuthController::class, 'refresh']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class]);

// Product routes (public)
Route::get('/api/v1/products', [App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/v1/products/{id}', [App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/v1/regions', [App\Product\Controller\ProductController::class, 'regions']);

// Domain routes (public)
Route::get('/api/v1/domain/check/{domain}/{tld}', [App\Domain\Controller\DomainController::class, 'check']);
Route::get('/api/v1/domain/tlds', [App\Domain\Controller\DomainController::class, 'tlds']);

// Payment webhooks (no auth, signature verified)
Route::post('/api/v1/payments/webhook/stripe', [App\Payment\Controller\PaymentController::class, 'stripeWebhook']);

// === User authenticated routes ===
Route::group('/api/v1', function () {
    // Profile
    Route::get('/user/profile', [App\User\Controller\ProfileController::class, 'show']);
    Route::put('/user/profile', [App\User\Controller\ProfileController::class, 'update']);
    Route::post('/user/kyc', [App\User\Controller\KycController::class, 'submit']);
    Route::get('/user/balance', [App\User\Controller\BalanceController::class, 'index']);
    Route::get('/user/notifications', [App\Notification\Controller\NotificationController::class, 'index']);

    // Cart & Orders
    Route::post('/cart', [App\Order\Controller\OrderController::class, 'addToCart']);
    Route::get('/cart', [App\Order\Controller\OrderController::class, 'cart']);
    Route::delete('/cart/{id}', [App\Order\Controller\OrderController::class, 'removeFromCart']);
    Route::post('/orders', [App\Order\Controller\OrderController::class, 'store']);
    Route::get('/orders', [App\Order\Controller\OrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [App\Order\Controller\OrderController::class, 'show']);
    Route::get('/orders/{id}/payment-methods', [App\Payment\Controller\PaymentController::class, 'availableChannels']);
    Route::post('/orders/{id}/pay', [App\Payment\Controller\PaymentController::class, 'pay']);

    // Resources
    Route::get('/resources', [App\Provisioning\Controller\ResourceController::class, 'myResources']);
    Route::get('/resources/{id}', [App\Provisioning\Controller\ResourceController::class, 'show']);
    Route::get('/resources/{id}/status', [App\Provisioning\Controller\ResourceController::class, 'status']);
    Route::get('/resources/{id}/console', [App\Provisioning\Controller\ResourceController::class, 'consoleUrl']);

    // DNS
    Route::get('/dns/{domain}', [App\Domain\Controller\DomainController::class, 'listRecords']);
    Route::post('/dns/{domain}/records', [App\Domain\Controller\DomainController::class, 'addRecord']);
    Route::delete('/dns/{domain}/records/{id}', [App\Domain\Controller\DomainController::class, 'deleteRecord']);

    // Tickets
    Route::post('/tickets', [App\Ticket\Controller\TicketController::class, 'create']);
    Route::get('/tickets', [App\Ticket\Controller\TicketController::class, 'myTickets']);
    Route::get('/tickets/{id}', [App\Ticket\Controller\TicketController::class, 'show']);
    Route::post('/tickets/{id}/reply', [App\Ticket\Controller\TicketController::class, 'reply']);

    // Supplier
    Route::post('/supplier/apply', [App\Supplier\Controller\SupplierController::class, 'apply']);
    Route::get('/supplier/settlements', [App\Supplier\Controller\SupplierController::class, 'settlements']);
    Route::post('/supplier/withdraw', [App\Supplier\Controller\SupplierController::class, 'withdraw']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class]);

// === Admin routes ===
Route::group('/admin/api/v1', function () {
    Route::get('/dashboard', [App\Admin\Controller\DashboardController::class, 'index']);

    // Users
    Route::get('/users', [App\Admin\Controller\UserController::class, 'index']);
    Route::get('/users/{id}', [App\Admin\Controller\UserController::class, 'show']);
    Route::put('/users/{id}/status', [App\Admin\Controller\UserController::class, 'updateStatus']);
    Route::get('/kyc', [App\Admin\Controller\DashboardController::class, 'kycList']);
    Route::post('/kyc/{id}/approve', [App\Admin\Controller\DashboardController::class, 'kycApprove']);
    Route::post('/kyc/{id}/reject', [App\Admin\Controller\DashboardController::class, 'kycReject']);

    // Products
    Route::post('/products', [App\Admin\Controller\ProductController::class, 'store']);
    Route::put('/products/{id}', [App\Admin\Controller\ProductController::class, 'update']);
    Route::delete('/products/{id}', [App\Admin\Controller\ProductController::class, 'destroy']);
    Route::post('/products/{productId}/skus', [App\Admin\Controller\ProductController::class, 'storeSku']);
    Route::put('/skus/{id}', [App\Admin\Controller\ProductController::class, 'updateSku']);
    Route::post('/skus/{skuId}/region-price', [App\Admin\Controller\ProductController::class, 'setRegionPrice']);

    // Orders
    Route::get('/orders', [App\Admin\Controller\OrderController::class, 'index']);
    Route::get('/orders/{id}', [App\Admin\Controller\OrderController::class, 'show']);
    Route::post('/orders/{id}/refund', [App\Admin\Controller\OrderController::class, 'refund']);

    // Provisioning
    Route::get('/provisioning/tasks', [App\Provisioning\Controller\TaskController::class, 'index']);
    Route::post('/provisioning/tasks/{id}/retry', [App\Provisioning\Controller\TaskController::class, 'retry']);
    Route::post('/provisioning/resources/{id}/upgrade', [App\Provisioning\Controller\ResourceController::class, 'upgrade']);
    Route::post('/provisioning/resources/{id}/destroy', [App\Provisioning\Controller\ResourceController::class, 'destroy']);
    Route::get('/provisioning/hosts', [App\Provisioning\Controller\HostController::class, 'index']);

    // Payment
    Route::get('/payments/channels', [App\Admin\Controller\PaymentController::class, 'channels']);
    Route::put('/payments/channels/{id}', [App\Admin\Controller\PaymentController::class, 'updateChannel']);
    Route::get('/payments/transactions', [App\Admin\Controller\PaymentController::class, 'transactions']);
    Route::get('/payments/reconcile', [App\Admin\Controller\PaymentController::class, 'reconcile']);

    // Supplier management
    Route::get('/suppliers', [App\Admin\Controller\SupplierController::class, 'index']);
    Route::post('/suppliers/{id}/approve', [App\Admin\Controller\SupplierController::class, 'approve']);
    Route::post('/suppliers/{id}/settle', [App\Admin\Controller\SupplierController::class, 'generateSettlement']);
    Route::post('/suppliers/withdraws/{id}/approve', [App\Admin\Controller\SupplierController::class, 'approveWithdraw']);

    // Tickets
    Route::get('/tickets', [App\Ticket\Controller\TicketController::class, 'index']);
    Route::post('/tickets/{id}/assign', [App\Ticket\Controller\TicketController::class, 'assign']);
    Route::post('/tickets/{id}/close', [App\Ticket\Controller\TicketController::class, 'close']);

    // System
    Route::get('/audit-logs', [App\Admin\Controller\SystemController::class, 'auditLogs']);
    Route::put('/system/config', [App\Admin\Controller\SystemController::class, 'updateConfig']);

    // Reports
    Route::get('/reports/revenue', [App\Report\Controller\ReportController::class, 'revenue']);
    Route::get('/reports/supplier', [App\Report\Controller\ReportController::class, 'supplier']);
    Route::get('/reports/region', [App\Report\Controller\ReportController::class, 'byRegion']);

    // Monitoring
    Route::get('/monitor/dashboard', [App\Monitor\Controller\MonitorController::class, 'dashboard']);
    Route::get('/monitor/resources/{id}', [App\Monitor\Controller\MonitorController::class, 'resourceMetrics']);
})->middleware([Common\Encryption\Middleware\EncryptionMiddleware::class, Common\Auth\Middleware\AuthMiddleware::class]);
