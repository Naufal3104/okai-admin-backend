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
                'total_mitra'       => Affiliates::count(),
                'total_referrals'   => AffiliateCommissions::count(),
                'pending_commissions' => WithdrawalRequest::where('status', 'pending')->sum('amount'),
                'paid_commissions'    => WithdrawalRequest::where('status', 'approved')->sum('amount'),
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
        $withdrawal = WithdrawalRequest::find($id);

        if (!$withdrawal) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        }

        $request->validate([
            'status' => 'required|in:approved,rejected',
            'admin_note' => 'nullable|string'
        ]);

        $withdrawal->update([
            'status' => $request->status,
            'admin_note' => $request->admin_note
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status diperbarui'
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