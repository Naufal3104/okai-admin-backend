<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AffiliateController; // Import Controller baru Dhandi

/*
|--------------------------------------------------------------------------
| API Routes - OKAI Admin Portal
|--------------------------------------------------------------------------
*/

// --- AUTHENTICATION ROUTES ---
Route::post('/login', [UserController::class, 'login']);
Route::post('/logout', [UserController::class, 'logout']);
Route::post('/register', [UserController::class, 'register']);
Route::get('/auth/google/url', [UserController::class, 'getGoogleUrl']);
Route::get('/verify-email/{id}/{hash}', [UserController::class, 'verifyEmail'])->name('verification.verify');

// --- PRODUCT MANAGEMENT ---
Route::get('/products', [ProductController::class, 'index']);
Route::apiResource('/users', UserController::class);
Route::apiResource('promotions', PromotionController::class);
Route::apiResource('/orders', OrderController::class);
// tambahan dhandi
Route::post('/products', [ProductController::class, 'store']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::put('/products/{id}', [ProductController::class, 'update']);
Route::delete('/products/{id}', [ProductController::class, 'destroy']);

// --- RESOURCE ROUTES (CRUD) ---
Route::apiResource('users', UserController::class);
Route::apiResource('promotions', PromotionController::class);

// --- AFFILIATE MANAGEMENT (Tambahan Dhandi) ---
Route::prefix('affiliate')->group(function () {
    Route::get('/stats', [AffiliateController::class, 'getStats']);
    Route::get('/withdrawals', [AffiliateController::class, 'getWithdrawals']);
    Route::post('/withdrawals/{id}/status', [AffiliateController::class, 'updateStatus']);
    Route::get('/list', [AffiliateController::class, 'getAffiliateList']);
});