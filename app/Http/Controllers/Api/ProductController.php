<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    // 1. Ambil Semua Data (Index)
    public function index(Request $request)
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
                'price' => $product->price,
                'stock' => $product->warehouseStocks()->sum('stock'), // Kalkulasi dari semua gudang
                'status' => $product->is_active ? 'Published' : 'Draft',
                'image_url' => $product->image_url,
                'description' => $product->description,
                'is_affiliate_enabled' => (bool) $product->is_affiliate_enabled,
                
                // 👇 UBAH JADI FORMAT BARU 👇
                'commission_type' => $product->commission_type, 
                'commission_value' => $product->commission_value,
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
            'sku' => 'nullable|string|unique:products,sku',
            'category' => 'required|string',
            'price' => 'required|numeric',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'image_file' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'boolean',
            'is_affiliate_enabled' => 'in:0,1,true,false',
            // 👇 VALIDASI FORMAT BARU 👇
            'commission_type' => 'nullable|string|in:percent,fixed',
            'commission_value' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        if (empty($validatedData['sku'])) {
            $validatedData['sku'] = 'OK-' . strtoupper(substr(uniqid(), -5));
        }

        // --- LOGIKA GAMBAR ---
        $finalImageUrl = null;

        if ($request->hasFile('image_file')) {
            $file = $request->file('image_file');
            $path = $file->store('products', 'public');
            $finalImageUrl = asset('storage/' . $path);
        } elseif (!empty($validatedData['image_url'])) {
            $finalImageUrl = $validatedData['image_url'];
        }

        unset($validatedData['image_file']);
        $validatedData['image_url'] = $finalImageUrl;

        // 👇 SETTING DEFAULT KOMISI JIKA KOSONG 👇
        $validatedData['commission_type'] = $request->input('commission_type', 'percent');
        $validatedData['commission_value'] = $request->input('commission_value', 0);

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

        $data = $product->toArray();
        $data['stock'] = $product->warehouseStocks()->sum('stock');

        return response()->json([
            'success' => true,
            'data' => $data
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
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku,' . $id, 
            'category' => 'required|string',
            'price' => 'required|numeric',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'image_file' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'in:0,1,true,false', 
            'is_affiliate_enabled' => 'in:0,1,true,false', 
            // 👇 VALIDASI FORMAT BARU 👇
            'commission_type' => 'nullable|string|in:percent,fixed',
            'commission_value' => 'nullable|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        
        // --- LOGIKA GAMBAR ---
        $finalImageUrl = $product->image_url;

        if ($request->hasFile('image_file')) {
            $file = $request->file('image_file');
            $path = $file->store('products', 'public');
            $finalImageUrl = asset('storage/' . $path);
        } elseif (isset($validatedData['image_url'])) {
            $finalImageUrl = $validatedData['image_url'];
        }

        // --- UPDATE DATA MANUAL (Anti Gagal) ---
        $product->name = $validatedData['name'];
        $product->sku = $validatedData['sku'] ?? $product->sku;
        $product->category = $validatedData['category'];
        $product->price = $validatedData['price'];
        $product->description = $validatedData['description'] ?? '';
        $product->image_url = $finalImageUrl;
        
        // Trik konversi string "1"/"0" dari Frontend menjadi integer untuk MySQL
        $product->is_active = in_array($request->input('is_active'), [1, '1', true, 'true'], true) ? 1 : 0;
        $product->is_affiliate_enabled = in_array($request->input('is_affiliate_enabled'), [1, '1', true, 'true'], true) ? 1 : 0;
        
        // 👇 SIMPAN NILAI KOMISI BARU 👇
        $product->commission_type = $request->input('commission_type', 'percent');
        $product->commission_value = $request->input('commission_value', 0);

        // Eksekusi Simpan ke DB
        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diperbarui!',
            'data' => $product
        ]);
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