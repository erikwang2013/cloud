<?php
use Webman\Route;

// Health
Route::get('/health', [\App\Controller\HealthController::class, 'index']);

// Auth routes (public, with rate limit)
Route::group('/api/v1/auth', function () {
    Route::post('/register', [\App\User\Controller\AuthController::class, 'register']);
    Route::post('/login', [\App\User\Controller\AuthController::class, 'login']);
    Route::post('/refresh', [\App\User\Controller\AuthController::class, 'refresh']);
});

// Products (public read)
Route::get('/api/v1/products', [\App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/v1/products/{id}', [\App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/v1/regions', [\App\Product\Controller\ProductController::class, 'regions']);

// User authenticated routes
Route::group('/api/v1', function () {
    Route::get('/user/profile', [\App\User\Controller\ProfileController::class, 'show']);
})->middleware([\Common\Auth\Middleware\AuthMiddleware::class]);

// Admin routes
Route::group('/admin/api/v1', function () {
    Route::get('/dashboard', [\App\Admin\Controller\DashboardController::class, 'index']);
})->middleware([
    \Common\Auth\Middleware\AuthMiddleware::class,
]);

// Payment webhooks (no auth — signature verified instead)
Route::post('/api/v1/payments/webhook/stripe', [\App\Payment\Controller\PaymentController::class, 'stripeWebhook']);
