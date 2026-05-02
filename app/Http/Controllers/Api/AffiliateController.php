<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
// Pastikan import menggunakan nama file yang ada di folder Models kamu
use App\Models\Affiliates;
use App\Models\AffiliateCommissions;
use App\Models\WithdrawalRequest;

class AffiliateController extends Controller
{
    public function getStats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_mitra' => Affiliates::count(),
                'total_referrals' => AffiliateCommissions::count(),
                'pending_commissions' => WithdrawalRequest::where('status', 'pending')->sum('amount'),
                'paid_commissions' => WithdrawalRequest::where('status', 'approved')->sum('amount'),
            ]
        ]);
    } // <-- Pastikan kurung tutup ini ada

    public function getWithdrawals()
    {
        $requests = WithdrawalRequest::with('affiliate')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    } // <-- Pastikan kurung tutup ini ada

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:active,rejected'
        ]);

        // UBAH BAGIAN INI: Cari berdasarkan user_id, bukan id tabel affiliate
        $affiliate = Affiliates::where('id', $id)
            ->orWhere('user_id', $id) // Sebagai cadangan jika ID yang dikirim adalah ID User
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan di tabel affiliates (ID: ' . $id . ')'
            ], 404);
        }

        $affiliate->status = $request->status;

        if ($request->status === 'active' && empty($affiliate->affiliate_code)) {
            $singkatanNama = strtoupper(substr(str_replace(' ', '', $affiliate->full_name), 0, 4));
            $affiliate->affiliate_code = 'OKAI-' . $singkatanNama . rand(10, 99);
        }

        $affiliate->save();

        return response()->json([
            'success' => true,
            'message' => 'Status mitra berhasil diperbarui!'
        ]);
    }

    public function getAffiliateList()
    {
        // Ganti Affiliate:: jadi Affiliates:: sesuai nama modelmu
        $affiliates = Affiliates::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $affiliates
        ]);
    }
} // <-- Penutup Class