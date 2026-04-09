<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    // 1. Ambil Semua Data (Index)
    public function index(Request $request) // Tambahkan Request $request di sini
    {
        // Tangkap kata kunci pencarian dari React
        $search = $request->query('search');

        // Tarik data: Jika ada pencarian, saring berdasarkan Nama atau SKU
        $rawProducts = Products::when($search, function ($query, $search) {
            return $query->where('name', 'like', '%' . $search . '%')
                         ->orWhere('sku', 'like', '%' . $search . '%');
        })->orderBy('id', 'desc')->get();

        $formattedProducts = $rawProducts->map(function ($product) {
            return [
                'id' => $product->id,
                'sku' => $product->sku ?? 'NO-SKU', 
                'name' => $product->name,
                'category' => $product->category ?? 'General',
                'price' => 'Rp ' . number_format($product->price, 0, ',', '.'), 
                'stock' => $product->stock,
                'warehouse' => $product->warehouse ?? 'Gudang Utama (Surabaya)',
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
            'sku' => 'nullable|string|unique:products,sku', // Dibuat nullable (opsional)
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

        // Mengambil hanya data yang sudah tervalidasi
        $validatedData = $validator->validated();

        // Jika SKU kosong dari frontend, buatkan otomatis menggunakan waktu acak sementara
        if (empty($validatedData['sku'])) {
            $validatedData['sku'] = 'OK-' . strtoupper(substr(uniqid(), -5));
        }

        $product = Products::create($validatedData);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan!',
            'data' => $product
        ], 201);
    }

    // 3. Tampilkan Satu Produk (Show)
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

    // 4. Update Produk (Update)
    public function update(Request $request, $id)
    {
        $product = Products::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'sometimes|required|string|unique:products,sku,'.$id, 
            'category' => 'sometimes|required|string',
            'warehouse' => 'sometimes|required|string',
            'price' => 'sometimes|required|numeric',
            'stock' => 'sometimes|required|integer',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Gunakan validated() agar lebih aman
        $product->update($validator->validated());

        return response()->json(['success' => true, 'message' => 'Produk diperbarui!', 'data' => $product], 200);
    }

    // 5. Hapus Produk (Destroy)
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