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
            'affiliate_code' => 'nullable|string', // 👈 Tambahkan validasi untuk menerima kode
        ]);

        return DB::transaction(function () use ($request) {
           $userId = $request->user()->id;

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
                    'payer_email' => $request->user()->email,
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

    public function markAsPaid($id)
    {
        $order = Orders::find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        $order->status = 'paid';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil ditandai sebagai Lunas (Paid).',
            'data' => $order
        ]);
    }

    public function shipWithBiteship($id, Request $request)
    {
        $order = Orders::with(['user', 'order_items.product'])->find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        // Siapkan item untuk Biteship
        $biteshipItems = [];
        foreach ($order->order_items as $item) {
            $biteshipItems[] = [
                'name' => $item->product ? $item->product->name : 'Produk OKAI',
                'description' => 'Produk KAMBI',
                'value' => $item->price,
                'quantity' => $item->quantity,
                'weight' => 500 // Asumsi berat 500 gram per produk
            ];
        }

        $apiKey = env('BITESHIP_API_KEY');

        // Parameter Kurir - Default ke JNE Reguler jika tidak dikirim dari FE
        $courier_company = $request->input('courier_company', 'jne');
        $courier_type = $request->input('courier_type', 'reg');

        $payload = [
            'shipper_contact_name' => 'Gudang OKAI Official',
            'shipper_contact_phone' => '081234567890',
            'shipper_contact_email' => 'admin@okai.com',
            'shipper_organization' => 'OKAI Official',
            'origin_contact_name' => 'Gudang OKAI',
            'origin_contact_phone' => '081234567890',
            'origin_address' => 'Jalan Kali Rungkut No 5, Surabaya',
            'origin_postal_code' => 60293, 
            'destination_contact_name' => $order->user ? $order->user->name : 'Customer',
            'destination_contact_phone' => ($order->user && $order->user->phone_number) ? $order->user->phone_number : '081233334444',
            'destination_contact_email' => $order->user ? $order->user->email : 'customer@okai.com',
            'destination_address' => $order->address ?? 'Jalan Sudirman No 1, Jakarta Pusat',
            'destination_postal_code' => 12160, // Gunakan kode pos Jakarta yang valid untuk testing
            'courier_company' => $courier_company,
            'courier_type' => $courier_type,
            'delivery_type' => 'now',
            'order_note' => 'Hati-hati pecah belah',
            'items' => $biteshipItems
        ];

        // Lakukan pemanggilan API ke Biteship
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => $apiKey,
            'Content-Type' => 'application/json'
        ])->post('https://api.biteship.com/v1/orders', $payload);

        if ($response->successful()) {
            $biteshipData = $response->json();
            
            // Simpan detail Biteship ke pesanan
            $order->courier_company = $courier_company;
            $order->courier_type = $courier_type;
            $order->shipping_cost = $biteshipData['price'] ?? 10000;
            $order->waybill_id = $biteshipData['courier']['waybill_id'] ?? 'RESI-'.rand(1000,9999);
            $order->status = 'shipped';
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil diserahkan ke Ekspedisi melalui Biteship!',
                'data' => $order,
                'biteship' => $biteshipData
            ]);
        } else {
            // Tampilkan error asli dari Biteship agar kita tahu apa yang salah (misal: Alamat kurang lengkap)
            $errorData = $response->json();
            
            return response()->json([
                'success' => false,
                'message' => 'Biteship menolak pengiriman. Periksa pesan error di bawah.',
                'error_from_biteship' => $errorData,
                'debug_payload_sent' => $payload // Untuk membantu debugging
            ], 400);
        }
    }

    public function simulateDelivery($id)
    {
        $order = Orders::find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        $order->status = 'delivered';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Simulasi Ekspedisi: Pesanan berhasil ditandai sebagai Diterima (Delivered).',
            'data' => $order
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

    public function xenditWebhook(Request $request)
    {
        // 1. Ambil data payload dari Xendit
        $external_id = $request->input('external_id'); // Format: INV-2026xxxx-xxxx
        $status = $request->input('status'); // 'PAID', 'EXPIRED', dll

        // 2. Jika status dibayar, perbarui status order di database
        if ($status === 'PAID') {
            $order = Orders::where('invoice_no', $external_id)->first();
            if ($order && $order->status === 'pending') {
                $order->status = 'paid';
                $order->save();
            }
        }

        // 3. Wajib membalas dengan status 200 OK agar Xendit tidak mencoba mengirim ulang webhook
        return response()->json(['success' => true, 'message' => 'Webhook diterima.']);
    }
}
