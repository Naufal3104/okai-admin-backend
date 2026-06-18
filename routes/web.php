<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/google/callback', [UserController::class, 'handleGoogleCallback']);

Route::get('/affiliate/receipt/{id}', function ($id) {
    $withdrawal = \App\Models\WithdrawalRequest::with('affiliate.user')->findOrFail($id);
    
    // Pastikan hanya yang di-approve yang bisa dicetak
    if ($withdrawal->status !== 'approved') {
        abort(403, 'Tanda terima hanya tersedia untuk pencairan yang disetujui.');
    }
    
    return view('receipt', compact('withdrawal'));
});
