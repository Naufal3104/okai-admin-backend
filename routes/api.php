<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AffiliateController; 
use App\Http\Controllers\CartController;
use App\Http\Controllers\Api\ReviewController;

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

// Katalog Produk
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::get('/products/{id}/reviews', [ReviewController::class, 'index']);

// Xendit Webhook
Route::post('/xendit/webhook', [OrderController::class, 'xenditWebhook']);

// Affiliate Tracking
Route::post('/affiliate/track', [\App\Http\Controllers\Api\AffiliateController::class, 'trackClick']);
// ==========================================O
// 🔒 JALUR VIP / KHUSUS (Wajib Login & Bawa Token)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {
    
    // --- AUTHENTICATION ---
    Route::post('/logout', [UserController::class, 'logout']);

    // --- TRANSAKSI ---
    Route::get('/active-shipments', [OrderController::class, 'getActiveShipments']);
    Route::apiResource('orders', OrderController::class);
    Route::get('/orders/{id}/available-warehouses', [OrderController::class, 'getAvailableWarehouses']);
    Route::post('/orders/{id}/mark-paid', [OrderController::class, 'markAsPaid']);
    Route::post('/orders/{id}/ship', [OrderController::class, 'shipWithBiteship']);
    Route::post('/orders/{id}/simulate-delivery', [OrderController::class, 'simulateDelivery']);

    // --- MANAJEMEN ADMIN ---
    Route::apiResource('users', UserController::class);
    Route::apiResource('promotions', PromotionController::class);
    Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class);
    Route::post('/warehouses/{id}/products', [\App\Http\Controllers\Api\WarehouseController::class, 'updateProductStock']);
    Route::patch('/affiliates/{id}/status', [AffiliateController::class, 'updateStatus']);
    Route::get('/track', [OrderController::class, 'trackResi']);    
    // Tambah/Edit/Hapus Produk
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    Route::get('/carts', [CartController::class, 'index']);       // Ambil data keranjang
    Route::post('/carts', [CartController::class, 'store']);     // Tambah barang ke keranjang
    Route::put('/carts/{id}', [CartController::class, 'update']); // Ubah jumlah kuantitas
    Route::delete('/carts/{id}', [CartController::class, 'destroy']); // Hapus barang dari keranjang

    // --- AFFILIATE MANAGEMENT ---
    // 👇 ROUTE BARU UNTUK MENERIMA DATA DARI FORMULIR PENGAJUAN (WEB KAMBI)
    Route::post('/affiliate-requests', [AffiliateController::class, 'storeRequest']);
    // Route untuk mengambil detail satu affiliator spesifik
    Route::get('/affiliates/{id}', [AffiliateController::class, 'show']);
    
    // 👇 ROUTE BARU UNTUK TOMBOL TERIMA/TOLAK (ADMIN PORTAL)
    Route::patch('/affiliates/{id}/status', [AffiliateController::class, 'updateAffiliateStatus']);

    // --- CEK STATUS AFFILIATE USER SAAT INI ---
    Route::get('/user/affiliate-status', [AffiliateController::class, 'checkUserStatus']);
    
    // --- AMBIL PRODUK YANG BISA DI-AFILIASIKAN ---
    Route::get('/affiliate/available-products', [AffiliateController::class, 'getAvailableProducts']);
    Route::post('/user/affiliate-withdraw', [App\Http\Controllers\Api\AffiliateController::class, 'requestWithdrawal']);
    Route::prefix('affiliate')->group(function () {
        Route::get('/stats', [AffiliateController::class, 'getStats']);
        Route::get('/withdrawals', [AffiliateController::class, 'getWithdrawals']);
        Route::post('/withdrawals/{id}/status', [AffiliateController::class, 'updateWithdrawalStatus']);
        Route::get('/list', [AffiliateController::class, 'getAffiliateList']);
        
    });

    Route::post('/reviews', [ReviewController::class, 'store']);

   
    
});

 //testing
    
    Route::get('/test-delivery/{id}', [\App\Http\Controllers\Api\OrderController::class, 'simulateDelivery']);