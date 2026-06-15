<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliates;
use Illuminate\Http\Request;
use App\Models\Orders;
use App\Models\Products;
use App\Models\Carts;
use App\Models\OrderItems; 
use App\Models\Promotions; 
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Orders::with(['user', 'items.product'])->orderBy('id', 'desc');
        
        $adminRoles = ['superadmin', 'super_admin', 'admin', 'administrator'];

        if (!$request->user()->hasAnyRole($adminRoles)) {
            $query->where('user_id', $request->user()->id);
        }

        $rawOrders = $query->get();

        foreach ($rawOrders as $order) {
            if ($order->status === 'pending' && strtolower($order->payment_method) !== 'cod' && $order->invoice_no) {
                try {
                    $secretKey = env('XENDIT_SECRET_KEY');
                    $xenditCheck = \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => 'Basic ' . base64_encode($secretKey . ':')
                    ])->get("https://api.xendit.co/v2/invoices?external_id=" . $order->invoice_no);

                    if ($xenditCheck->successful()) {
                        $invoices = $xenditCheck->json();
                        foreach ($invoices as $inv) {
                            if ($inv['external_id'] === $order->invoice_no && in_array(strtoupper($inv['status']), ['PAID', 'SETTLED'])) {
                                $order->status = 'paid';
                                $order->save();
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error("Gagal sync Xendit di Index: " . $e->getMessage());
                }
            }
        }

        $formattedOrders = $rawOrders->map(function ($order) {
            $itemString = $order->items->map(function ($item) {
                $productName = $item->product ? $item->product->name : 'Produk Dihapus';
                return $productName . ' (' . $item->quantity . 'x)';
            })->implode(', ');

            $rawItemsArray = $order->items->map(function ($item) use ($order) {
                $isReviewed = \App\Models\Review::where('order_id', $order->id)
                                                ->where('product_id', $item->product_id)
                                                ->exists();

                return [
                    'id' => $item->product_id,
                    'name' => $item->product ? $item->product->name : 'Produk Dihapus',
                    'qty' => $item->quantity,
                    'price' => $item->price,
                    'is_reviewed' => $isReviewed,
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

    public function store(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
            'payment_method' => 'required|string',
            'total_price' => 'required|numeric',
            'items' => 'required|array',
            'affiliate_code' => 'nullable|string', 
            'promotion_code' => 'nullable|string', 
            'discount_amount' => 'nullable|numeric', 
        ]);

        return DB::transaction(function () use ($request) {
           $userId = $request->user()->id;

            // 1. CEGAH SELF-REFERRAL (DI AKALI) 🔥
            $affiliateId = null;
            if ($request->filled('affiliate_code')) {
                $affiliate = Affiliates::where('affiliate_code', $request->affiliate_code)->first();
                // Jika affiliate valid DAN bukan milik pembeli sendiri, baru masukkan ID-nya
                if ($affiliate && $affiliate->user_id !== $userId) {
                    $affiliateId = $affiliate->id;
                }
            }

            // 1.5. LOGIKA KUPON PROMO
            $id_promotion = null;
            if ($request->filled('promotion_code')) {
                $promo = Promotions::where('code', strtoupper(trim($request->promotion_code)))->first();
                if ($promo) {
                    $id_promotion = $promo->id_promotion;
                    $promo->increment('used_count'); 
                }
            }

            // 2. Buat Header Order
            $order = Orders::create([
                'invoice_no' => 'INV-' . date('Ymd') . '-' . rand(1000, 9999), 
                'user_id' => $userId, 
                'total_price' => $request->total_price,
                'address' => $request->address,
                'payment_method' => $request->payment_method,
                'status' => 'pending', 
                'affiliate_id' => $affiliateId, 
                'id_promotion' => $id_promotion, 
            ]);

            // 3. Simpan Detail Produk yang dibeli
            foreach ($request->items as $item) {
                $product = Products::find($item['product_id']);
                if ($product) {
                    OrderItems::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $item['qty'],
                        'price' => $product->price 
                    ]);
                }
            }

            // 4. Bersihkan Keranjang
            Carts::where('user_id', $userId)->delete();

            // 5. Integrasi Xendit
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
                    $order->update(['payment_url' => $paymentUrl]);
                } else {
                    throw new \Exception("Gagal membuat tagihan pembayaran."); 
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat!',
                'data' => $order,
                'payment_url' => $paymentUrl
            ], 201);
        });
    }

    public function show($id)
    {
        $originalId = $id;
        if (str_starts_with($id, 'ORD-')) {
            $parts = explode('-', $id);
            $originalId = end($parts);
        }

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

        $formattedItems = $order->order_items->map(function ($item) {
            return [
                'id' => $item->product_id,
                'name' => $item->product ? $item->product->name : 'Produk Dihapus',
                'qty' => $item->quantity,
                'price' => $item->price,
            ];
        });

        $data = $order->toArray();
        $data['id'] = 'ORD-' . ($order->created_at ? $order->created_at->format('Y') : date('Y')) . '-' . str_pad($order->id, 4, '0', STR_PAD_LEFT);
        $data['raw_id'] = $order->id;
        $data['customer'] = $order->user ? $order->user->name : 'Guest/Deleted';
        $data['items'] = $formattedItems;
        $data['total'] = $order->total_price; 
        $data['method'] = $order->payment_method ?? 'Standard Reguler'; 
        $data['date'] = $order->created_at ? $order->created_at->format('d M Y') : '-'; 

        // LOGIKA HITUNG DISKON PROMO
        $discountAmount = 0;
        $promoCode = null;
        if ($order->id_promotion) {
            $promo = \App\Models\Promotions::where('id_promotion', $order->id_promotion)->first();
            if ($promo) {
                $promoCode = $promo->code;
                $subtotal = $order->order_items->sum(function($item) {
                    return $item->price * $item->quantity;
                });
                
                if ($promo->type === 'percentage') {
                    $discountAmount = ($subtotal * $promo->value) / 100;
                } else {
                    $discountAmount = $promo->value;
                }
                if ($discountAmount > $subtotal) $discountAmount = $subtotal;
            }
        }
        $data['discount_amount'] = $discountAmount;
        $data['promo_code'] = $promoCode;

        // SYNC STATUS PEMBAYARAN XENDIT
        if ($order->status === 'pending' && strtolower($order->payment_method) !== 'cod' && $order->invoice_no) {
            try {
                $secretKey = env('XENDIT_SECRET_KEY');
                $xenditCheck = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($secretKey . ':')
                ])->get("https://api.xendit.co/v2/invoices?external_id=" . $order->invoice_no);

                if ($xenditCheck->successful()) {
                    $invoices = $xenditCheck->json();
                    foreach ($invoices as $inv) {
                        if ($inv['external_id'] === $order->invoice_no && in_array(strtoupper($inv['status']), ['PAID', 'SETTLED'])) {
                            $order->status = 'paid';
                            $order->save();
                            $data['status'] = 'paid'; 
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::error("Gagal sync Xendit: " . $e->getMessage());
            }
        }

        // FETCH TRACKING DARI BITESHIP
        $data['tracking'] = null;
        if ($order->waybill_id && $order->courier_company) {
            $trackingResponse = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => env('BITESHIP_API_KEY'),
            ])->get("https://api.biteship.com/v1/trackings/{$order->waybill_id}/courier/{$order->courier_company}");

            if ($trackingResponse->successful()) {
                $trackingData = $trackingResponse->json();
                $data['tracking'] = [
                    'waybill_id' => $trackingData['waybill_id'],
                    'status' => $trackingData['status'],
                    'courier' => $trackingData['courier'],
                    'link' => "https://biteship.com/id/tracking/{$order->waybill_id}" 
                ];

                // OTOMATISASI STATUS DELIVERED + CAIRKAN KOMISI
                if (strtolower($trackingData['status']) === 'delivered' && $order->status !== 'delivered') {
                    $order->status = 'delivered';
                    $order->save();
                    $data['status'] = 'delivered';

                    // 💰 RUMUS BARU KOMISI AFILIASI (PER PRODUK) 💰
                    if ($order->affiliate_id) {
                        $affiliate = \App\Models\Affiliates::find($order->affiliate_id);
                        if ($affiliate) {
                            $totalCommission = 0;
                            
                            foreach ($order->order_items as $item) {
                                if ($item->product && $item->product->is_affiliate_enabled) {
                                    if ($item->product->commission_type === 'fixed') {
                                        $itemCommission = $item->product->commission_value * $item->quantity;
                                    } else {
                                        $itemCommission = ($item->product->commission_value / 100) * $item->price * $item->quantity;
                                    }
                                    $totalCommission += $itemCommission;
                                }
                            }
                            
                            if ($totalCommission > 0) {
                                $existingCommission = \Illuminate\Support\Facades\DB::table('affiliate_commissions')
                                    ->where('order_id', $order->id)->first();

                                if (!$existingCommission) {
                                    \Illuminate\Support\Facades\DB::table('affiliate_commissions')->insert([
                                        'affiliate_id' => $affiliate->id,
                                        'order_id' => $order->id,
                                        'commission_amount' => $totalCommission, 
                                        'status' => 'pending', 
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        return response()->json([
            "success" => true,
            "data" => $data
        ], 200);
    }

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
                'item' => 'Order #' . $order->id, 
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

        $warehouseId = $request->input('warehouse_id');
        $warehouse = \App\Models\Warehouses::find($warehouseId);

        if (!$warehouse) {
            return response()->json(['success' => false, 'message' => 'Gudang asal tidak valid'], 400);
        }

        foreach ($order->order_items as $item) {
            $productWarehouse = \App\Models\ProductWarehouses::where('id_warehouse', $warehouseId)
                ->where('id_product', $item->product_id)
                ->first();

            if (!$productWarehouse || $productWarehouse->stock < $item->quantity) {
                return response()->json([
                    'success' => false, 
                    'message' => "Stok produk '{$item->product->name}' di gudang {$warehouse->name} tidak mencukupi."
                ], 400);
            }
        }

        foreach ($order->order_items as $item) {
            \App\Models\ProductWarehouses::where('id_warehouse', $warehouseId)
                ->where('id_product', $item->product_id)
                ->decrement('stock', $item->quantity);
        }

        $biteshipItems = [];
        foreach ($order->order_items as $item) {
            $biteshipItems[] = [
                'name' => $item->product ? $item->product->name : 'Produk OKAI',
                'description' => 'Produk KAMBI',
                'value' => $item->price,
                'quantity' => $item->quantity,
                'weight' => 500 
            ];
        }

        $apiKey = env('BITESHIP_API_KEY');

        $courier_company = $request->input('courier_company', 'jne');
        $courier_type = $request->input('courier_type', 'reg');
        $admin_phone = $request->input('admin_phone', '081234567890');

        $destAddress = $order->address ?? '';
        $destParts = array_map('trim', explode(',', $destAddress));
        $destPostalCode = 12160; 
        
        if (count($destParts) >= 5) {
            $parsedPostal = array_pop($destParts);
            if (is_numeric($parsedPostal)) {
                $destPostalCode = (int) $parsedPostal;
            } else {
                array_push($destParts, $parsedPostal);
            }
            
            if (count($destParts) >= 4) {
                array_pop($destParts);
                array_pop($destParts);
                array_pop($destParts);
            }
            $destAddress = implode(', ', $destParts); 
        }

        $payload = [
            'shipper_contact_name' => 'Gudang OKAI Official - ' . $warehouse->name,
            'shipper_contact_phone' => $admin_phone,
            'shipper_contact_email' => 'admin@okai.com',
            'shipper_organization' => 'OKAI Official',
            'origin_contact_name' => 'Admin ' . $warehouse->name,
            'origin_contact_phone' => $admin_phone,
            'origin_address' => $warehouse->address . ', ' . $warehouse->city,
            'origin_postal_code' => (int) $warehouse->postal_code, 
            'destination_contact_name' => $order->user ? $order->user->name : 'Customer',
            'destination_contact_phone' => ($order->user && $order->user->phone_number) ? $order->user->phone_number : '081233334444',
            'destination_contact_email' => $order->user ? $order->user->email : 'customer@okai.com',
            'destination_address' => $destAddress,
            'destination_postal_code' => $destPostalCode,
            'courier_company' => $courier_company,
            'courier_type' => $courier_type,
            'delivery_type' => 'now',
            'order_note' => 'Hati-hati pecah belah',
            'items' => $biteshipItems
        ];

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => $apiKey,
            'Content-Type' => 'application/json'
        ])->post('https://api.biteship.com/v1/orders', $payload);

        if ($response->successful()) {
            $biteshipData = $response->json();
            
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
            $errorData = $response->json();
            
            if (isset($errorData['code']) && $errorData['code'] == 40002002) {
                 $biteshipData = [
                    'price' => 15000,
                    'courier' => [
                        'waybill_id' => 'FIKTIF-RESI-' . rand(10000, 99999)
                    ]
                ];

                $order->courier_company = $courier_company;
                $order->courier_type = $courier_type;
                $order->shipping_cost = $biteshipData['price'];
                $order->waybill_id = $biteshipData['courier']['waybill_id'];
                $order->status = 'shipped';
                $order->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Simulasi Ekspedisi: Pesanan berhasil diserahkan secara fiktif (API Key Biteship belum aktif).',
                    'data' => $order,
                    'biteship' => $biteshipData
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Biteship menolak pengiriman. Periksa pesan error di bawah.',
                'error_from_biteship' => $errorData,
                'debug_payload_sent' => $payload 
            ], 400);
        }
    }

    public function simulateDelivery($id)
    {
        $order = Orders::with('order_items.product')->find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        if ($order->status !== 'delivered') {
            $order->status = 'delivered';
            $order->save();

            // 💰🔥 RUMUS BARU KOMISI AFILIASI (PER PRODUK) 🔥💰
            if ($order->affiliate_id) {
                $affiliate = \App\Models\Affiliates::find($order->affiliate_id);
                if ($affiliate) {
                    $totalCommission = 0;
                    
                    foreach ($order->order_items as $item) {
                        if ($item->product && $item->product->is_affiliate_enabled) {
                            if ($item->product->commission_type === 'fixed') {
                                $itemCommission = $item->product->commission_value * $item->quantity;
                            } else {
                                $itemCommission = ($item->product->commission_value / 100) * $item->price * $item->quantity;
                            }
                            $totalCommission += $itemCommission;
                        }
                    }
                    
                    if ($totalCommission > 0) {
                        $existingCommission = \Illuminate\Support\Facades\DB::table('affiliate_commissions')
                            ->where('order_id', $order->id)
                            ->first();

                        if (!$existingCommission) {
                            \Illuminate\Support\Facades\DB::table('affiliate_commissions')->insert([
                                'affiliate_id' => $affiliate->id,
                                'order_id' => $order->id,
                                'commission_amount' => $totalCommission, 
                                'status' => 'pending',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Simulasi Ekspedisi: Pesanan berhasil ditandai sebagai Diterima (Delivered) & Komisi Masuk ke Riwayat.',
            'data' => $order
        ]);
    }

    public function trackResi(Request $request)
    {
        $awb = $request->query('awb');
        $courier = $request->query('courier');

        if (!$awb || !$courier) {
            return response()->json(['success' => false, 'message' => 'Resi dan Kurir wajib diisi'], 400);
        }

        try {
            $binderbyteKey = env('BINDERBYTE_API_KEY');
            if (!$binderbyteKey) {
                return response()->json(['success' => false, 'message' => 'API Key Binderbyte tidak ditemukan'], 500);
            }

            $response = \Illuminate\Support\Facades\Http::get("https://api.binderbyte.com/v1/track", [
                'api_key' => $binderbyteKey,
                'courier' => $courier,
                'awb' => $awb
            ]);

            if ($response->successful() && $response['status'] == 200) {
                return response()->json([
                    'success' => true, 
                    'data' => $response['data']
                ], 200);
            }

            return response()->json([
                'success' => false, 
                'message' => 'Resi tidak ditemukan di sistem Binderbyte atau belum diperbarui oleh pihak ekspedisi.',
                'error' => $response->json()
            ], 404);

        } catch (\Exception $e) {
            \Log::error("Binderbyte Tracking Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal terhubung dengan server tracking. Silakan coba lagi nanti.'
            ], 500);
        }
    }

    public function xenditWebhook(Request $request)
    {
        $external_id = $request->input('external_id'); 
        $status = strtoupper($request->input('status', '')); 

        if (in_array($status, ['PAID', 'SETTLED'])) {
            $order = Orders::where('invoice_no', $external_id)->first();
            if ($order && $order->status === 'pending') {
                $order->status = 'paid';
                $order->save();
            }
        }

        return response()->json(['success' => true, 'message' => 'Webhook diterima.']);
    }

    public function getAvailableWarehouses($id)
    {
        $order = Orders::with('order_items')->find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        $allWarehouses = \App\Models\Warehouses::all();
        $availableWarehouses = [];

        foreach ($allWarehouses as $warehouse) {
            $isSufficient = true;
            foreach ($order->order_items as $item) {
                $stock = \App\Models\ProductWarehouses::where('id_warehouse', $warehouse->id_warehouse)
                    ->where('id_product', $item->product_id)
                    ->value('stock') ?? 0;

                if ($stock < $item->quantity) {
                    $isSufficient = false;
                    break;
                }
            }

            if ($isSufficient) {
                $availableWarehouses[] = $warehouse;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $availableWarehouses
        ]);
    }
}