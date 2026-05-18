<?php

namespace App\Http\Controllers;

use App\Models\Carts;
use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    // 1. MENAMPILKAN ISI KERANJANG
    public function index()
    {
        // Mengambil keranjang milik user yang sedang login beserta data produknya (Eager Loading)
        $cartItems = Carts::with('product')
            ->where('user_id', Auth::id())
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cartItems
        ], 200);
    }

    // 2. MENAMBAH ATAU MEMPERBARUI BARANG DI KERANJANG
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        $userId = Auth::id();
        $productId = $request->product_id;
        $qty = $request->qty;

        // Cek stok produk terlebih dahulu untuk memastikan ketersediaan
        $product = Products::find($productId);
        if ($product->stock < $qty) {
            return response()->json([
                'success' => false,
                'message' => 'Stok produk tidak mencukupi.'
            ], 400);
        }

        // Cek apakah produk tersebut sudah ada di keranjang user
        $cartItem = Carts::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($cartItem) {
            // Jika sudah ada, akumulasikan jumlahnya
            $newQty = $cartItem->qty + $qty;
            
            if ($product->stock < $newQty) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total kuantitas di keranjang melebihi stok tersedia.'
                ], 400);
            }

            $cartItem->update(['qty' => $newQty]);
        } else {
            // Jika belum ada, buat pencatatan baru
            $cartItem = Carts::create([
                'user_id' => $userId,
                'product_id' => $productId,
                'qty' => $qty,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan ke keranjang.',
            'data' => $cartItem
        ], 200);
    }

    // 3. MENGUBAH JUMLAH BARANG SECARA LANGSUNG (Dari Halaman Cart)
    public function update(Request $request, $id)
    {
        $request->validate([
            'qty' => 'required|integer|min:1',
        ]);

        // Cari data keranjang dan pastikan itu milik user yang terautentikasi
        $cartItem = Carts::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Data keranjang tidak ditemukan.'
            ], 404);
        }

        // Validasi batasan stok produk
        $product = Products::find($cartItem->product_id);
        if ($product->stock < $request->qty) {
            return response()->json([
                'success' => false,
                'message' => 'Kuantitas melebihi stok produk yang tersedia.'
            ], 400);
        }

        $cartItem->update(['qty' => $request->qty]);

        return response()->json([
            'success' => true,
            'message' => 'Kuantitas berhasil diperbarui.'
        ], 200);
    }

    // 4. MENGHAPUS BARANG DARI KERANJANG
    public function destroy($id)
    {
        $cartItem = Carts::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Data keranjang tidak ditemukan.'
            ], 404);
        }

        $cartItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus dari keranjang.'
        ], 200);
    }
}