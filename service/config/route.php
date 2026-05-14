<?php
use Webman\Route;

// Health check
Route::get('/health', [\App\Controller\HealthController::class, 'index']);

// Auth routes — stubbed until Phase 1
Route::post('/api/v1/auth/register', function () {
    return json(['code' => 0, 'message' => 'Not implemented yet']);
});
Route::post('/api/v1/auth/login', function () {
    return json(['code' => 0, 'message' => 'Not implemented yet']);
});

// Products — stubbed
Route::get('/api/v1/products', function () {
    return json(['code' => 0, 'message' => 'ok', 'data' => []]);
});

// Payment webhooks
Route::post('/api/v1/payments/webhook/stripe', function () {
    return json(['code' => 0, 'message' => 'ok']);
});
