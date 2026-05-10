<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Orders;
use App\Models\OrderItems; // Pastikan model ini di-import! Sesuaikan namanya jika pakai OrderItem (tanpa s)
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Orders::with(['user', 'items.product'])->orderBy('id', 'desc');
        
        // 🚩 SOLUSI: Gunakan hasAnyRole untuk menangkap semua variasi penulisan admin
        $adminRoles = ['superadmin', 'super_admin', 'admin', 'administrator'];

        if (!$request->user()->hasAnyRole($adminRoles)) {
            // Jika bukan salah satu dari admin di atas, batasi hanya pesanan miliknya saja
            $query->where('user_id', $request->user()->id);
        }

        $rawOrders = $query->get();

        $formattedOrders = $rawOrders->map(function ($order) {
            // ... (biarkan kode mapping di bawahnya tetap sama persis seperti sebelumnya)
            $itemString = $order->items->map(function ($item) {
                $productName = $item->product ? $item->product->name : 'Produk Dihapus';
                return $productName . ' (' . $item->quantity . 'x)';
            })->implode(', ');

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
                'items_string' => $itemString ?: 'Tidak ada barang',
                'items' => $rawItemsArray,
                'total' => $order->total_price,
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
        ]);

        return DB::transaction(function () use ($request) {
            // 1. Buat Header Order
            $order = Orders::create([
                // Ganti $request->user()->id menjadi id() bawaan auth agar tidak crash
                'user_id' => auth()->id(), 
                
                'total_price' => $request->total_price,
                'address' => $request->address,
                'payment_method' => $request->payment_method,
                'status' => 'pending', 
                'affiliate_id' => $request->affiliate_id ?? null, 
            ]);

            // 2. Simpan Detail Produk yang dibeli
            foreach ($request->items as $item) {
                // Catatan: Gunakan OrderItems (pakai 's') jika nama model Akang OrderItems
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function trackResi(Request $request)
    {
        $awb = $request->query('awb');
        $courier = $request->query('courier');
        $apiKey = env('BINDERBYTE_API_KEY');

        if (!$awb || !$courier) {
            return response()->json(['success' => false, 'message' => 'Resi dan Kurir wajib diisi'], 400);
        }

        // Laravel yang menelpon Binderbyte secara diam-diam
        $response = \Illuminate\Support\Facades\Http::get("https://api.binderbyte.com/v1/track", [
            'api_key' => $apiKey,
            'courier' => $courier,
            'awb' => $awb
        ]);

        if ($response->successful() && $response['status'] == 200) {
            return response()->json(['success' => true, 'data' => $response['data']], 200);
        }

        return response()->json(['success' => false, 'message' => 'Resi tidak ditemukan atau server sibuk'], 404);
    }
}
