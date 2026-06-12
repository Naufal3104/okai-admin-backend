<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Models\Orders; 

class ReviewController extends Controller
{
    // 👇 Fungsi untuk menampilkan ulasan di halaman produk
    public function index($id)
    {
        $reviews = Review::join('users', 'reviews.user_id', '=', 'users.id')
                    ->where('product_id', $id)
                    ->select('reviews.*', 'users.name as user_name')
                    ->orderBy('reviews.created_at', 'desc')
                    ->get();

        $formattedReviews = $reviews->map(function ($review) {
            // Ambil array path gambar dari database
            $imagePaths = json_decode($review->images, true) ?: [];
            
            // Ubah path lokal menjadi URL penuh yang bisa diakses React
            $imageUrls = array_map(function ($path) {
                return asset('storage/' . $path);
            }, $imagePaths);

            return [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'images' => $imageUrls, // Kirim URL penuh ke frontend
                'admin_reply' => $review->admin_reply, // 🔥 INI TAMBAHANNYA BOSQUE! 🔥
                'created_at' => $review->created_at,
                'user' => [
                    'name' => $review->user_name
                ]
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedReviews
        ]);
    }

    // 👇 Fungsi untuk menyimpan ulasan & FOTO dari frontend
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'order_id' => 'required',
            'product_id' => 'required',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:5120' // Maks 5MB per foto
        ]);

        $order = Orders::where('id', $request->order_id)
                      ->where('user_id', $user->id)
                      ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak valid.'], 403);
        }

        if ($order->status !== 'delivered') {
            return response()->json(['success' => false, 'message' => 'Pesanan belum selesai.'], 400);
        }

        $existingReview = Review::where('user_id', $user->id)
                                ->where('order_id', $request->order_id)
                                ->where('product_id', $request->product_id)
                                ->first();

        if ($existingReview) {
            return response()->json(['success' => false, 'message' => 'Sudah dinilai.'], 400);
        }

        // 🔥 LOGIKA UPLOAD GAMBAR 🔥
        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                // Simpan gambar ke folder storage/app/public/reviews
                $path = $image->store('reviews', 'public');
                $imagePaths[] = $path;
            }
        }

        $review = Review::create([
            'user_id' => $user->id,
            'order_id' => $request->order_id,
            'product_id' => $request->product_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'images' => json_encode($imagePaths) // Simpan array path ke database
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Ulasan berhasil disimpan!',
            'data' => $review
        ], 201);
    }

    // ==========================================
    // FUNGSI UNTUK ADMIN MEMBALAS ULASAN
    // ==========================================
    public function reply(Request $request, $id)
    {
        // 1. Validasi inputan dari frontend admin
        $request->validate([
            'admin_reply' => 'required|string|max:1000'
        ]);

        // 2. Cari ulasan yang mau dibalas
        // Pastikan nama modelnya benar (Review tanpa S, sesuai konfirmasimu tadi)
        $review = \App\Models\Review::find($id); 

        if (!$review) {
            return response()->json([
                'success' => false, 
                'message' => 'Ulasan tidak ditemukan.'
            ], 404);
        }

        // 3. Simpan balasan
        $review->admin_reply = $request->admin_reply;
        $review->save();

        return response()->json([
            'success' => true,
            'message' => 'Balasan berhasil dikirim!',
            'data' => $review
        ]);
    }
}