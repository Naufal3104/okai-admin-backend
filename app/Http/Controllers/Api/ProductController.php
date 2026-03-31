<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    // 1. Ambil Semua Data (Index)
    public function index()
    {
        $rawProducts = Products::orderBy('id', 'desc')->get();

        $formattedProducts = $rawProducts->map(function ($product) {
            return [
                'id' => $product->id,
                'sku' => $product->sku ?? 'NO-SKU', 
                'name' => $product->name,
                'category' => $product->category ?? 'General',
                'price' => $product->price,
                'stock' => $product->stock,
                'warehouse' => $product->warehouse ?? 'Gudang Utama',
                'status' => $product->is_active ? 'Published' : 'Draft',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedProducts
        ], 200);
    }

    // 2. Simpan Produk Baru (Store)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku', // Validasi agar SKU tidak kembar
            'category' => 'required|string',
            'warehouse' => 'required|string',
            'price' => 'required|numeric',
            'stock' => 'required|integer',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $product = Products::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan!',
            'data' => $product
        ], 201);
    }

        // Tambahkan di dalam ProductController
    public function show($id)
    {
        $product = Products::find($id);

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product
        ], 200);
    }

    // 3. Update Produk (Update)
    public function update(Request $request, $id)
    {
        $product = Products::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'sometimes|required|string|unique:products,sku,'.$id, // Abaikan SKU milik sendiri saat update
            'category' => 'sometimes|required|string',
            'warehouse' => 'sometimes|required|string',
            'price' => 'sometimes|required|numeric',
            'stock' => 'sometimes|required|integer',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $product->update($request->all());

        return response()->json(['success' => true, 'message' => 'Produk diperbarui!', 'data' => $product], 200);
    }

    // 4. Hapus Produk (Destroy)
    public function destroy($id)
    {
        $product = Products::find($id);

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan'], 404);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus!'
        ], 200);
    }
}