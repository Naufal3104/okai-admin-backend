<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Orders;
use App\Models\OrderItems; // Pastikan model ini di-import! Sesuaikan namanya jika pakai OrderItem (tanpa s)
use Illuminate\Support\Facades\DB;
use App\Models\Affiliates;
class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Tarik data order (Bisa difilter untuk admin vs customer nanti jika diperlukan)
        // Sementara kita filter berdasarkan user yang sedang login agar customer hanya lihat pesanan sendiri
        $query = Orders::with(['user', 'items.product'])->orderBy('id', 'desc');
        
        // Opsional: Jika yang akses bukan admin, tampilkan pesanan miliknya saja
        if (!$request->user()->hasRole('superadmin') && !$request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        $rawOrders = $query->get();

        $formattedOrders = $rawOrders->map(function ($order) {
            // Merangkum barang untuk keperluan tabel Web Admin (Teks)
            $itemString = $order->items->map(function ($item) {
                $productName = $item->product ? $item->product->name : 'Produk Dihapus';
                return $productName . ' (' . $item->quantity . 'x)';
            })->implode(', ');

            // Memformat ulang items untuk keperluan Front-End Customer (Array)
            $rawItemsArray = $order->items->map(function ($item) {
                return [
                    'id' => $item->product_id,
                    'name' => $item->product ? $item->product->name : 'Produk Dihapus',
                    'qty' => $item->quantity,
                    'price' => $item->price,
                ];
            });

            return [
                'id' => 'ORD-' . ($order->created_at ? $order->created_at->format('Y') : date('Y')) . '-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                'raw_id' => $order->id,
                'customer' => $order->user ? $order->user->name : 'Guest/Deleted',
                
                'items_string' => $itemString ?: 'Tidak ada barang', // <-- Untuk Admin
                'items' => $rawItemsArray, // <-- Untuk Next.js Front-End

                'total' => $order->total_price, // Biarkan angka asli agar Next.js bisa format sendiri
                'method' => $order->payment_method ?? 'Standard Reguler',
                'status' => $order->status ?? 'pending',
                'date' => $order->created_at ? $order->created_at->format('d M Y') : '-',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedOrders
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     * INI ADALAH MESIN UNTUK PROSES CHECKOUT DARI NEXT.JS
     */
    public function store(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
            'payment_method' => 'required|string',
            'total_price' => 'required|numeric',
            'items' => 'required|array',
            'affiliate_code' => 'nullable|string', // 👈 Tambahkan validasi untuk menerima kode
        ]);

        return DB::transaction(function () use ($request) {
            
            // 👇 1. LOGIC MENERJEMAHKAN KODE REFERRAL KE ID AFILIATOR 👇
            $affiliateId = null;
            if ($request->filled('affiliate_code')) {
                // Cari afiliator di database berdasarkan kodenya
                $affiliate = Affiliates::where('affiliate_code', $request->affiliate_code)->first();
                if ($affiliate) {
                    $affiliateId = $affiliate->id; // Dapat angkanya! (misal: 1)
                }
            }
            // 👆 ======================================================== 👆

            // 2. Buat Header Order
            $order = Orders::create([
                // Kita ganti pakai $request->user()->id biar garis merah VS Code hilang
                'user_id' => $request->user()->id, 
                
                'total_price' => $request->total_price,
                'address' => $request->address,
                'payment_method' => $request->payment_method,
                'status' => 'pending', 
                'affiliate_id' => $affiliateId, // 👈 Masukkan hasil terjemahannya ke sini
            ]);

            // 3. Simpan Detail Produk yang dibeli
            foreach ($request->items as $item) {
                OrderItems::create([
                    'order_id' => $order->id,
                    'product_id' => $item['id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat!',
                'data' => $order
            ], 201);
        });
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        // Cari pesanan berdasarkan ID mentahnya
        $order = Orders::with(['user', 'items.product'])->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        // Keamanan: Pastikan user cuma bisa lihat pesanannya sendiri (kecuali dia Admin)
        if (!$request->user()->hasRole('superadmin') && !$request->user()->hasRole('admin')) {
            if ($order->user_id !== $request->user()->id) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
            }
        }

        $rawItemsArray = $order->items->map(function ($item) {
            return [
                'id' => $item->product_id,
                'name' => $item->product ? $item->product->name : 'Produk Dihapus',
                'qty' => $item->quantity,
                'price' => $item->price,
            ];
        });

        // Format data untuk dikirim ke Next.js
        $formattedOrder = [
            'id' => 'ORD-' . ($order->created_at ? $order->created_at->format('Y') : date('Y')) . '-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
            'raw_id' => $order->id,
            'customer' => $order->user ? $order->user->name : 'Guest/Deleted',
            'items' => $rawItemsArray,
            'total' => $order->total_price,
            'method' => $order->payment_method ?? 'Transfer Bank',
            'status' => $order->status ?? 'pending',
            'date' => $order->created_at ? $order->created_at->format('d M Y, H:i') : '-',
            'address' => $order->address,
            'invoice_no' => $order->invoice_no,
        ];

        return response()->json([
            'success' => true,
            'data' => $formattedOrder
        ], 200);
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
