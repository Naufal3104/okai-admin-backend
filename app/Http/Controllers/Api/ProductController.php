<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Products;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        // 1. Ambil semua data produk dari database, urutkan dari yang terbaru
        $rawProducts = Products::orderBy('id', 'desc')->get();

        // 2. Format ulang datanya agar sesuai dengan harapan React Anda
        $formattedProducts = $rawProducts->map(function ($product) {
            return [
                'id' => $product->id,
                // Membuat SKU otomatis berdasarkan ID produk
                'sku' => 'OK-' . str_pad($product->id, 5, '0', STR_PAD_LEFT), 
                'name' => $product->name,
                'category' => 'General', // Nilai bawaan karena belum ada di DB
                // Mengubah angka 850000 menjadi format Rp 850.000
                'price' => 'Rp ' . number_format($product->price, 0, ',', '.'), 
                'stock' => $product->stock,
                'warehouse' => 'Gudang Utama (Surabaya)', // Nilai bawaan
                // Menerjemahkan is_active (1/0) menjadi Published/Draft
                'status' => $product->is_active ? 'Published' : 'Draft', 
            ];
        });

        // 3. Kirim ke React
        return response()->json([
            'success' => true,
            'data' => $formattedProducts
        ], 200);
    }
}
