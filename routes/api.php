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
Route::get('/products/{id}/reviews', function ($id) {
    return response()->json([
        'success' => true,
        'data' => [] // Dummy response kosong agar tidak error 404
    ]);
});
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::get('/products/{id}/reviews', [ReviewController::class, 'index']);

// Xendit Webhook
Route::post('/xendit/webhook', [OrderController::class, 'xenditWebhook']);
// Biteship Webhook
Route::post('/biteship/webhook', [OrderController::class, 'biteshipWebhook']);

// Affiliate Tracking
Route::post('/affiliate/track', [\App\Http\Controllers\Api\AffiliateController::class, 'trackClick']);
Route::post('/affiliate/validate-code', [\App\Http\Controllers\Api\AffiliateController::class, 'validateCode']);

// Homepage Dinamis
Route::get('/homepage', [\App\Http\Controllers\Api\HomepageController::class, 'index']);

// ==========================================
// 🔒 JALUR VIP / KHUSUS (Wajib Login & Bawa Token)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/shipping/rate', [\App\Http\Controllers\Api\OrderController::class, 'getShippingRate']);

    Route::get('/system-settings', [\App\Http\Controllers\Api\SystemSettingController::class, 'getSettings']);
    Route::post('/system-settings', [\App\Http\Controllers\Api\SystemSettingController::class, 'updateSettings']);

    // --- AUTHENTICATION ---
    Route::post('/logout', [UserController::class, 'logout']);

    // --- TRANSAKSI ---
    Route::post('/reviews', function () {
        return response()->json([
            'success' => true,
            'message' => 'Ulasan berhasil dikirim (Dummy)'
        ]);
    });
    Route::get('/active-shipments', [OrderController::class, 'getActiveShipments']);
    Route::apiResource('orders', OrderController::class);
    Route::get('/orders/{id}/available-warehouses', [OrderController::class, 'getAvailableWarehouses']);
    Route::post('/orders/{id}/mark-paid', [OrderController::class, 'markAsPaid']);
    Route::post('/orders/{id}/ship', [OrderController::class, 'shipWithBiteship']);
    Route::post('/orders/{id}/ship-manual', [OrderController::class, 'shipManual']);
    Route::post('/orders/{id}/simulate-delivery', [OrderController::class, 'simulateDelivery']);
    Route::post('/orders/{id}/sync-tracking', [OrderController::class, 'syncTracking']);

    // --- MANAJEMEN ADMIN ---
    Route::apiResource('users', UserController::class);
    Route::get('/analytics/dashboard', [\App\Http\Controllers\Api\AnalyticsController::class, 'index']);
    // --- PENGATURAN HOMEPAGE (CMS) ---
    Route::post('/homepage-settings', [\App\Http\Controllers\Api\HomepageController::class, 'update']);
    Route::get('/dashboard/summary', [\App\Http\Controllers\Api\DashboardController::class, 'index']);

    // Route Cek Promo
    Route::post('/promotions/check', [PromotionController::class, 'check']);
    Route::apiResource('promotions', PromotionController::class);
    
    Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class);
    Route::post('/warehouses/{id}/products', [\App\Http\Controllers\Api\WarehouseController::class, 'updateProductStock']);
    Route::get('/track', [OrderController::class, 'trackResi']);
    
    // Tambah/Edit/Hapus Produk
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Keranjang
    Route::get('/carts', [CartController::class, 'index']);       
    Route::post('/carts', [CartController::class, 'store']);     
    Route::put('/carts/{id}', [CartController::class, 'update']); 
    Route::delete('/carts/{id}', [CartController::class, 'destroy']); 

    // Rute untuk membalas ulasan pembeli
    Route::post('/reviews/{id}/reply', [\App\Http\Controllers\Api\ReviewController::class, 'reply']);

    // --- AFFILIATE MANAGEMENT ---
    Route::post('/affiliate-requests', [AffiliateController::class, 'storeRequest']);
    Route::get('/affiliates/{id}', [AffiliateController::class, 'show']);

    // ROUTE UNTUK TOMBOL TERIMA/TOLAK (ADMIN PORTAL)
    Route::patch('/affiliates/{id}/status', [AffiliateController::class, 'updateAffiliateStatus']);

    // CEK STATUS AFFILIATE USER SAAT INI
    Route::get('/user/affiliate-status', [AffiliateController::class, 'checkUserStatus']);

    // AMBIL PRODUK YANG BISA DI-AFILIASIKAN & WITHDRAW
    Route::get('/affiliate/available-products', [AffiliateController::class, 'getAvailableProducts']);
    Route::post('/user/affiliate-withdraw', [App\Http\Controllers\Api\AffiliateController::class, 'requestWithdrawal']);
    
    // Data Statistik Admin Affiliate
    Route::prefix('affiliate')->group(function () {
        Route::get('/stats', [AffiliateController::class, 'getStats']);
        Route::get('/withdrawals', [AffiliateController::class, 'getWithdrawals']);
        Route::post('/withdrawals/{id}/status', [AffiliateController::class, 'updateWithdrawalStatus']);
        Route::post('/withdrawals/{id}/pay', [AffiliateController::class, 'markWithdrawalAsPaid']);
        Route::get('/list', [AffiliateController::class, 'getAffiliateList']);
    });
    
    Route::post('/user/affiliate-bank', [AffiliateController::class, 'updateBankInfo']);
    
    // Chatbot route
    Route::post('/chat/assistant', [\App\Http\Controllers\Api\ChatbotController::class, 'handleChat']);
    Route::post('/reviews', [ReviewController::class, 'store']);
});

// Testing
Route::get('/test-delivery/{id}', [\App\Http\Controllers\Api\OrderController::class, 'simulateDelivery']);