<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/google/callback', [UserController::class, 'handleGoogleCallback']);

Route::get('/affiliate/receipt/{id}', function ($id) {
    $withdrawal = \App\Models\WithdrawalRequest::with('affiliate.user')->findOrFail($id);
    
    // Tanda terima tersedia jika berstatus approved atau paid
    if (!in_array($withdrawal->status, ['approved', 'paid'])) {
        abort(403, 'Tanda terima hanya tersedia untuk pencairan yang disetujui atau sudah dibayar.');
    }
    
    return view('receipt', compact('withdrawal'));
});

Route::post('/affiliate/receipt/{id}/pay', function ($id) {
    $withdrawal = \App\Models\WithdrawalRequest::with('affiliate')->findOrFail($id);

    if ($withdrawal->status !== 'approved') {
        return back()->with('error', 'Hanya penarikan berstatus approved yang bisa ditandai paid.');
    }

    $affiliate = $withdrawal->affiliate;

    // Validasi data rekening wajib diisi sebelum status diubah ke paid
    if (empty($affiliate->account_number) || empty($affiliate->bank_name) || empty($affiliate->account_holder_name)) {
        return back()->with('error', 'Data rekening mitra belum lengkap! Harap lengkapi Nama Bank, Nomor Rekening, dan Nama Pemilik Rekening di profil mitra terlebih dahulu.');
    }

    \Illuminate\Support\Facades\DB::transaction(function () use ($withdrawal, $affiliate) {
        // 1. Set status WithdrawalRequest ke paid
        $withdrawal->status = 'paid';
        $withdrawal->save();

        // 2. Set status AffiliateCommissions yang bersangkutan ke paid
        if (str_contains($withdrawal->admin_note, 'Otomatis dari Pesanan')) {
            $parts = explode(' ', $withdrawal->admin_note);
            $invoiceNo = end($parts);
            $order = \App\Models\Orders::where('invoice_no', $invoiceNo)->first();
            if ($order) {
                \App\Models\AffiliateCommissions::where('order_id', $order->id)
                    ->where('affiliate_id', $affiliate->id)
                    ->update(['status' => 'paid']);
            }
        } else {
            \App\Models\AffiliateCommissions::where('affiliate_id', $affiliate->id)
                ->where('status', 'approved')
                ->update(['status' => 'paid']);
        }
    });

    return redirect()->back()->with('success', 'Status pencairan berhasil diubah menjadi PAID.');
})->name('receipt.pay');
