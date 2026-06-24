<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Promotions;
use Illuminate\Support\Facades\Validator;

class PromotionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $rawPromotions = Promotions::orderBy('id_promotion', 'desc')->get();

        $formatted = $rawPromotions->map(function ($promo) {
            return [
                'id' => $promo->id_promotion, // Disesuaikan agar terbaca di React
                'code' => $promo->code,
                'type' => $promo->type == 'percentage' ? 'Percentage' : 'Fixed Amount',
                'value' => $promo->type == 'percentage' ? $promo->value . '%' : 'Rp ' . number_format($promo->value, 0, ',', '.'),
                'limit' => $promo->max_usage,
                'used' => $promo->used_count ?? 0,
                'expiry' => $promo->end_date ? date('d M Y', strtotime($promo->end_date)) : '-',
                'status' => $promo->is_active ? 'Active' : 'Inactive',
            ];
        });

        return response()->json(['success' => true, 'data' => $formatted], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:promotions,code',
            'description' => 'nullable|string',
            'type' => 'required|in:percentage,fixed_amount',
            'value' => 'required|numeric',
            'max_usage' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promotion = Promotions::create($validator->validated());
        return response()->json(['success' => true, 'data' => $promotion], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $promotion = Promotions::where('id_promotion', $id)->first();

        if (!$promotion) {
            return response()->json(['success' => false, 'message' => 'Data Promosi tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $promotion], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // 🚩 BUG FIX: Menggunakan where agar selaras dengan primary key kustom
        $promotion = Promotions::where('id_promotion', $id)->first();
        
        if (!$promotion) {
            return response()->json(['success' => false, 'message' => 'Promo tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|required|string|unique:promotions,code,' . $id . ',id_promotion',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|in:percentage,fixed_amount',
            'value' => 'sometimes|required|numeric',
            'max_usage' => 'sometimes|required|integer',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promotion->update($validator->validated());
        return response()->json(['success' => true, 'data' => $promotion], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // 🚩 BUG FIX: Menggunakan where
        $promotion = Promotions::where('id_promotion', $id)->first();
        
        if (!$promotion) {
            return response()->json(['success' => false, 'message' => 'Promo tidak ditemukan'], 404);
        }
        $promotion->delete();
        return response()->json(['success' => true, 'message' => 'Promo dihapus'], 200);
    }

    /**
     * ========================================================
     * FITUR BARU: Mengecek Kupon dari Halaman Checkout React
     * ========================================================
     */
    public function check(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = strtoupper(trim($request->code));
        $promo = Promotions::where('code', $code)->first();

        // 1. Cek apakah kupon ada
        if (!$promo) {
            return response()->json(['success' => false, 'message' => 'Kode kupon tidak valid atau tidak ditemukan.'], 404);
        }

        // 2. Cek apakah status kupon aktif
        if (!$promo->is_active) {
            return response()->json(['success' => false, 'message' => 'Kode kupon ini sedang dinonaktifkan.'], 400);
        }

        // 3. Cek Masa Berlaku (Tanggal)
        $today = date('Y-m-d');
        if ($promo->start_date && $today < $promo->start_date) {
            return response()->json(['success' => false, 'message' => 'Kupon ini belum bisa digunakan saat ini.'], 400);
        }
        if ($promo->end_date && $today > $promo->end_date) {
            return response()->json(['success' => false, 'message' => 'Masa berlaku kupon ini sudah habis.'], 400);
        }

        // 4. Cek Kuota Pemakaian
        if ($promo->max_usage > 0 && $promo->used_count >= $promo->max_usage) {
            return response()->json(['success' => false, 'message' => 'Kuota penggunaan kupon ini telah habis.'], 400);
        }

        // 5. Susun format balasan yang dimengerti oleh React
        return response()->json([
            'success' => true,
            'message' => 'Kupon berhasil diterapkan!',
            'data' => [
                'id' => $promo->id_promotion,
                'code' => $promo->code,
                // Ubah format string agar sesuai dengan logika React ('percent' atau 'fixed')
                'discount_type' => $promo->type === 'percentage' ? 'percent' : 'fixed',
                'discount_value' => $promo->value,
            ]
        ], 200);
    }
}