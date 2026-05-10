<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AffiliateController; 

/*
|--------------------------------------------------------------------------
| API Routes - OKAI Store & Admin Portal
|--------------------------------------------------------------------------
*/

// ==========================================
// 🔓 JALUR UMUM (Akses Bebas Tanpa Login)
// ==========================================
Route::post('/login', [UserController::class, 'login']);
Route::post('/register', [UserController::class, 'register']);
Route::get('/auth/google/url', [UserController::class, 'getGoogleUrl']);
Route::get('/verify-email/{id}/{hash}', [UserController::class, 'verifyEmail'])->name('verification.verify');

// Katalog Produk (Customer bebas lihat tanpa login)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);


// ==========================================
// 🔒 JALUR VIP / KHUSUS (Wajib Login & Bawa Token)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {
    
    // --- AUTHENTICATION ---
    Route::post('/logout', [UserController::class, 'logout']);

    // --- TRANSAKSI (Customer & Admin) ---
    // 👇 INI DIA KUNCINYA! Sekarang route pesanan dijaga ketat
    Route::apiResource('orders', OrderController::class); 

    // --- MANAJEMEN ADMIN ---
    Route::apiResource('users', UserController::class);
    Route::apiResource('promotions', PromotionController::class);
    Route::patch('/affiliates/{id}/status', [AffiliateController::class, 'updateStatus']);
    Route::get('/track', [OrderController::class, 'trackResi']);
    
    // Tambah/Edit/Hapus Produk
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // --- AFFILIATE MANAGEMENT ---
    Route::prefix('affiliate')->group(function () {
        Route::get('/stats', [AffiliateController::class, 'getStats']);
        Route::get('/withdrawals', [AffiliateController::class, 'getWithdrawals']);
        Route::post('/withdrawals/{id}/status', [AffiliateController::class, 'updateStatus']);
        Route::get('/list', [AffiliateController::class, 'getAffiliateList']);
    });

});