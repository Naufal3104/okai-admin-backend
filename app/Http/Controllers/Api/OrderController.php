<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliates;
use Illuminate\Http\Request;
use App\Models\Orders;
use App\Models\Products;
use App\Models\Carts;
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
            $userId = auth()->id();

            // 1. Terjemahkan Kode Afiliasi (Frontend mengirim 'affiliate_code', bukan ID)
            $affiliateId = null;
            if ($request->filled('affiliate_code')) {
                // Cari ID Affiliate berdasarkan kode yang dikirim dari React
                $affiliate = Affiliates::where('affiliate_code', $request->affiliate_code)->first();
                if ($affiliate) {
                    $affiliateId = $affiliate->id;
                }
            }

            // 2. Buat Header Order
            $order = Orders::create([
                // WAJIB ADA: Nomor referensi unik untuk Xendit dan pelacakan resi
                'invoice_no' => 'INV-' . date('Ymd') . '-' . rand(1000, 9999), 
                
                'user_id' => $userId, 
                'total_price' => $request->total_price,
                'address' => $request->address,
                'payment_method' => $request->payment_method,
                'status' => 'pending', 
                'affiliate_id' => $affiliateId, 
            ]);

            // 3. Simpan Detail Produk yang dibeli
            foreach ($request->items as $item) {
                // AMAN DARI HACKER: Ambil data produk asli dari database
                $product = Products::find($item['product_id']);

                if ($product) {
                    OrderItems::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $item['qty'],
                        'price' => $product->price 
                    ]);

                    // Kurangi stok produk secara otomatis
                    $product->decrement('stock', $item['qty']);
                }
            }

            // 4. Bersihkan Keranjang di Database setelah pesanan dibuat
            Carts::where('user_id', $userId)->delete();

            // 5. Integrasi Xendit (Jika Metode Bukan COD)
            $paymentUrl = null;
            if ($request->payment_method !== 'cod') {
                $secretKey = env('XENDIT_SECRET_KEY');
                
                $xenditResponse = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($secretKey . ':')
                ])->post('https://api.xendit.co/v2/invoices', [
                    'external_id' => $order->invoice_no,
                    'amount' => $order->total_price,
                    'payer_email' => auth()->user()->email,
                    'description' => 'Pembayaran Pesanan ' . $order->invoice_no,
                    'success_redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/orders',
                    'failure_redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/checkout',
                ]);

                if ($xenditResponse->successful()) {
                    $paymentUrl = $xenditResponse->json()['invoice_url'];
                } else {
                    // Batalkan seluruh transaksi DB jika Xendit sedang error
                    throw new \Exception("Gagal membuat tagihan pembayaran."); 
                }
            }

            // 6. Kembalikan Respons ke Frontend
            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat!',
                'data' => $order,
                'payment_url' => $paymentUrl // URL Xendit (Atau bernilai null jika COD)
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
    public function show($id)
    {
        // Jika ID diawali dengan ORD-, ambil angka di paling belakang (ID asli)
        $originalId = $id;
        if (str_starts_with($id, 'ORD-')) {
            $parts = explode('-', $id);
            $originalId = end($parts);
        }

        // Cari pesanan berdasarkan ID mentahnya atau nomor invoice
        $order = Orders::with(['user', 'order_items.product'])
            ->where('id', $originalId)
            ->orWhere('invoice_no', $id)
            ->first();

        if (!$order) {
            return response()->json([
                "success" => false,
                "message" => "Data pesanan tidak ditemukan."
            ], 404);
        }

        // Format data agar compatible dengan Next.js (Store) dan React (Admin)
        $formattedItems = $order->order_items->map(function ($item) {
            return [
                'id' => $item->product_id,
                'name' => $item->product ? $item->product->name : 'Produk Dihapus',
                'qty' => $item->quantity,
                'price' => $item->price,
            ];
        });

        // Mapping data untuk menyamakan dengan format index dan kebutuhan frontend
        $data = $order->toArray();
        $data['id'] = 'ORD-' . ($order->created_at ? $order->created_at->format('Y') : date('Y')) . '-' . str_pad($order->id, 4, '0', STR_PAD_LEFT);
        $data['raw_id'] = $order->id;
        $data['customer'] = $order->user ? $order->user->name : 'Guest/Deleted';
        $data['items'] = $formattedItems; // Digunakan oleh Next.js Store
        $data['total'] = $order->total_price; // Digunakan oleh Next.js Store
        $data['method'] = $order->payment_method ?? 'Standard Reguler'; // Digunakan oleh Next.js Store
        $data['date'] = $order->created_at ? $order->created_at->format('d M Y') : '-'; // Digunakan oleh Next.js Store

        return response()->json([
            "success" => true,
            "data" => $data
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

    /**
     * Get orders that are not pending for logistics tracking.
     */
    public function getActiveShipments()
    {
        $orders = Orders::where('status', '!=', 'pending')
            ->with('user')
            ->orderBy('updated_at', 'desc')
            ->get();

        $hasWaybill = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'waybill_id');

        $formatted = $orders->map(function ($order) use ($hasWaybill) {
            return [
                'id' => $order->id,
                'resi' => $hasWaybill ? $order->waybill_id : null,
                'invoice_no' => $order->invoice_no,
                'item' => 'Order #' . $order->id, // Bisa dikembangkan untuk ambil nama produk pertama
                'customer' => $order->user ? $order->user->name : 'Guest',
                'status' => ucfirst($order->status),
                'lastLocation' => 'Click to track',
                'updated' => $order->updated_at->diffForHumans(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    public function trackResi(Request $request)
    {
        $awb = $request->query('awb');
        $courier = $request->query('courier');
        $apiKey = env('BINDERBYTE_API_KEY');

        if (!$awb || !$courier) {
            return response()->json(['success' => false, 'message' => 'Resi dan Kurir wajib diisi'], 400);
        }

        // --- DINAMIS: Ambil Nomor Telepon Pembeli ---
        $destination = null;
        
        // Cari order berdasarkan AWB/waybill_id (jika disimpan di DB) atau invoice_no
        // Note: Sesuaikan kolom mana yang menyimpan nomor resi di database Anda
        $order = Orders::where('invoice_no', $awb)
            ->orWhere('id', str_replace('ORD-', '', $awb)) 
            ->with('user')
            ->first();

        if ($order && $order->user) {
            // Error Handling: Cek apakah kolom phone_number sudah ada di database
            // Ini untuk mencegah crash jika DB Admin belum menambah kolom tersebut
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'phone_number')) {
                $destination = $order->user->phone_number;
            }
        }

        // Persiapkan parameter untuk Binderbyte
        $params = [
            'api_key' => $apiKey,
            'courier' => $courier,
            'awb' => $awb
        ];

        // Tambahkan destination jika ada (diperlukan beberapa ekspedisi)
        if ($destination) {
            $params['destination'] = $destination;
        }

        // Laravel yang menelpon Binderbyte secara diam-diam
        $response = \Illuminate\Support\Facades\Http::get("https://api.binderbyte.com/v1/track", $params);

        if ($response->successful() && $response['status'] == 200) {
            return response()->json(['success' => true, 'data' => $response['data']], 200);
        }

        // Jika gagal karena butuh telepon (beberapa API Binderbyte return specific error)
        return response()->json([
            'success' => false, 
            'message' => 'Resi tidak ditemukan atau server sibuk. Pastikan nomor telepon sudah terdaftar jika diperlukan.'
        ], 404);
    }
}
