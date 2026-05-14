<?php
use Webman\Route;

// Health check
Route::get('/health', [\App\Controller\HealthController::class, 'index']);

// Auth routes
Route::post('/api/v1/auth/register', [\App\User\Controller\AuthController::class, 'register']);
Route::post('/api/v1/auth/login', [\App\User\Controller\AuthController::class, 'login']);
Route::post('/api/v1/auth/refresh', [\App\User\Controller\AuthController::class, 'refresh']);

// Product routes (public)
Route::get('/api/v1/products', [\App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/v1/products/{id}', [\App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/v1/regions', [\App\Product\Controller\ProductController::class, 'regions']);

// Cart and order routes (auth required)
Route::group('/api/v1', function () {
    Route::post('/cart', [\App\Order\Controller\OrderController::class, 'addToCart']);
    Route::get('/cart', [\App\Order\Controller\OrderController::class, 'cart']);
    Route::post('/orders', [\App\Order\Controller\OrderController::class, 'store']);
    Route::get('/orders', [\App\Order\Controller\OrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [\App\Order\Controller\OrderController::class, 'show']);
    Route::get('/orders/{id}/payment-methods', [\App\Payment\Controller\PaymentController::class, 'availableChannels']);
    Route::post('/orders/{id}/pay', [\App\Payment\Controller\PaymentController::class, 'pay']);
})->middleware([\Common\Auth\Middleware\AuthMiddleware::class]);

// Payment webhook (no auth, signature verification instead)
Route::post('/api/v1/payments/webhook/stripe', [\App\Payment\Controller\PaymentController::class, 'stripeWebhook']);
