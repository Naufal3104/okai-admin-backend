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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
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
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $promotion = Promotions::find($id);
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
        $promotion = Promotions::find($id);
        if (!$promotion) {
            return response()->json(['success' => false, 'message' => 'Promo tidak ditemukan'], 404);
        }
        $promotion->delete();
        return response()->json(['success' => true, 'message' => 'Promo dihapus'], 200);
    }
}
