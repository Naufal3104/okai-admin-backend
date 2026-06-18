<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\OrderItems;
use App\Models\Products;
use App\Models\Carts;
use App\Models\Affiliates;
use App\Models\Promotions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Orders::with(['user', 'items.product'])->orderBy('id', 'desc');

        $user = $request->user();
        $adminRoles = ['superadmin', 'super_admin', 'administrator'];

        if ($user->hasRole('admin')) {
            $warehouseId = $user->warehouse->id_warehouse ?? null;
            $query->where('warehouse_id', $warehouseId);
        } elseif (!$user->hasAnyRole($adminRoles)) {
            $query->where('user_id', $user->id);
        }

        $rawOrders = $query->get();

        foreach ($rawOrders as $order) {
            if ($order->status === 'pending' && strtolower($order->payment_method) !== 'cod' && $order->invoice_no) {
                try {
                    $secretKey = env('XENDIT_SECRET_KEY');
                    $xenditCheck = Http::withHeaders([
                        'Authorization' => 'Basic ' . base64_encode($secretKey . ':')
                    ])->get("https://api.xendit.co/v2/invoices?external_id=" . $order->invoice_no);

                    if ($xenditCheck->successful()) {
                        $invoices = $xenditCheck->json();
                        if (!empty($invoices)) {
                            $xenditInvoice = $invoices[0];
                            if ($xenditInvoice['status'] === 'PAID' || $xenditInvoice['status'] === 'SETTLED') {
                                $this->processOrderPaid($order);
                            } elseif ($xenditInvoice['status'] === 'EXPIRED') {
                                $order->status = 'cancelled';
                                $order->save();
                            }
                        }
                    }
                } catch (\Exception $e) {}
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

    // --- HELPER LOGIKA ONGKIR ---

    protected function getBinderbyteRegionId($apiKey, $cityName)
    {
        try {
            $response = Http::get('https://api.binderbyte.com/v1/locations', [
                'api_key' => $apiKey,
                'search' => trim($cityName)
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $data = $result['data'] ?? [];
                if (!empty($data)) return $data[0]['id'];
            }
        } catch (\Exception $e) {}
        return null;
    }

    protected function tryBiteshipRate($warehouse, $userPostalCode, $items)
    {
        $apiKey = null;
        $setting = \App\Models\SystemSetting::first();
        if ($setting && $setting->biteship_api_key) {
            try { $apiKey = Crypt::decryptString($setting->biteship_api_key); } catch (\Exception $e) {}
        }
        if (!$apiKey) $apiKey = env('BITESHIP_API_KEY');
        if (!$apiKey || !$warehouse->postal_code || !$userPostalCode) return null;

        try {
            $response = Http::withHeaders([
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json'
            ])->post('https://api.biteship.com/v1/rates/couriers', [
                'origin_postal_code' => (int) $warehouse->postal_code,
                'destination_postal_code' => (int) $userPostalCode,
                'couriers' => 'jne',
                'items' => collect($items)->map(function($i) {
                    return [
                        'name' => 'Produk Okai',
                        'value' => 100000,
                        'weight' => 1000,
                        'quantity' => $i['qty'] ?? $i['quantity']
                    ];
                })->toArray()
            ]);

            if ($response->successful()) {
                $rates = $response->json()['pricing'] ?? [];
                $jneReg = collect($rates)->filter(function($r) {
                    return strtolower($r['courier_code']) === 'jne' && str_contains(strtoupper($r['service_type']), 'REG');
                })->first();
                
                if ($jneReg) return ['price' => $jneReg['price'], 'etd' => $jneReg['duration']];
            }
        } catch (\Exception $e) {}
        return null;
    }

    protected function fetchUnifiedShipping($warehouseId, $userCity, $userPostalCode, $items)
    {
        $warehouse = \App\Models\Warehouses::find($warehouseId);
        if (!$warehouse) return ['price' => 25000, 'note' => 'Gudang tidak ditemukan.'];

        // 1. TRY BINDERBYTE
        $bbKey = null;
        $setting = \App\Models\SystemSetting::first();
        if ($setting && $setting->binderbyte_api_key) {
            try { $bbKey = Crypt::decryptString($setting->binderbyte_api_key); } catch (\Exception $e) {}
        }
        if (!$bbKey) $bbKey = env('BINDERBYTE_API_KEY');

        if ($bbKey) {
            $originId = $this->getBinderbyteRegionId($bbKey, $warehouse->city);
            $destinationId = $this->getBinderbyteRegionId($bbKey, $userCity);

            if ($originId && $destinationId) {
                try {
                    $bbResponse = Http::get('https://api.binderbyte.com/v1/cost', [
                        'api_key' => $bbKey,
                        'origin' => $originId,
                        'destination' => $destinationId,
                        'courier' => 'jne,sicepat,anteraja,pos',
                        'weight' => count($items) * 1000
                    ]);
                    if ($bbResponse->successful()) {
                        $results = $bbResponse->json()['data']['results'] ?? [];
                        $regService = null;

                        foreach ($results as $courier) {
                            $costs = $courier['costs'] ?? [];
                            $found = collect($costs)->filter(function($c) {
                                return strtoupper($c['service']) === 'REG' || strtoupper($c['service']) === 'REGULER';
                            })->first();
                            
                            if ($found) {
                                $regService = $found;
                                break;
                            }
                        }

                        if ($regService) {
                            $price = (int) $regService['price'];
                            if ($price > 1000000) $price = (int) ($price / 1000); // fix binderbyte over-multiplied price

                            $etd = $regService['estimated'] ?? '2-3 Hari';
                            if (stripos($etd, 'hari') === false) {
                                $etd .= ' Hari';
                            }

                            return [
                                'price' => $price, 
                                'etd' => ucwords($etd), 
                                'courier' => 'JNE REG'
                            ];
                        }
                    }
                } catch (\Exception $e) {}
            }
        }

        // 2. TRY BITESHP AS BACKUP
        $biteshipData = $this->tryBiteshipRate($warehouse, $userPostalCode, $items);
        if ($biteshipData) {
            return ['price' => $biteshipData['price'], 'etd' => $biteshipData['etd'], 'courier' => 'JNE REG'];
        }

        // 3. LAST FALLBACK
        return ['price' => 25000, 'etd' => '2-3 Hari', 'courier' => 'JNE REG', 'note' => 'Provider error, menggunakan tarif flat.'];
    }

    public function getShippingRate(Request $request)
    {
        $request->validate([
            'postal_code' => 'required|string',
            'city' => 'required|string',
            'province' => 'required|string',
            'items' => 'required|array'
        ]);

        // Cari Gudang Terbaik
        $warehouseGroups = [];
        foreach ($request->items as $item) {
            $product = Products::find($item['product_id']);
            if (!$product) continue;
            $pw = \App\Models\ProductWarehouses::with('warehouse')->where('id_product', $product->id)->where('stock', '>=', $item['qty'])->get();
            if ($pw->isEmpty()) return response()->json(['success' => false, 'message' => "Stok produk '{$product->name}' habis."], 400);
            
            $best = $pw->sortByDesc(fn($p) => (strtolower($p->warehouse->city ?? '') === strtolower($request->city) ? 100 : 0) + $p->stock)->first();
            $warehouseGroups[$best->id_warehouse][] = $item;
        }

        $firstWarehouseId = array_key_first($warehouseGroups);
        $res = $this->fetchUnifiedShipping($firstWarehouseId, $request->city, $request->postal_code, $request->items);

        return response()->json([
            'success' => true,
            'data' => [
                'courier' => 'JNE',
                'service' => 'REG',
                'price' => $res['price'],
                'estimated_days' => $res['etd'] ?? '-',
                'note' => $res['note'] ?? $res['courier']
            ]
        ]);
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
            'is_dropship' => 'nullable|boolean',
            'dropshipper_name' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($request) {
            $userId = $request->user()->id;

            $affiliateId = null;
            if ($request->filled('affiliate_code')) {
                $affiliate = Affiliates::where('affiliate_code', $request->affiliate_code)->first();
                if ($affiliate && $affiliate->user_id !== $userId) $affiliateId = $affiliate->id;
            }

            $id_promotion = null;
            if ($request->filled('promotion_code')) {
                $promo = Promotions::where('code', strtoupper(trim($request->promotion_code)))->first();
                if ($promo) { $id_promotion = $promo->id_promotion; $promo->increment('used_count'); }
            }

            $addressParts = array_map('trim', explode(',', $request->address));
            $userCity = $addressParts[count($addressParts) - 3] ?? '';
            $userProvince = $addressParts[count($addressParts) - 2] ?? '';
            $userPostalCode = $addressParts[count($addressParts) - 1] ?? '';

            $warehouseGroups = [];
            foreach ($request->items as $item) {
                $product = Products::find($item['product_id']);
                if (!$product) continue;
                $pws = \App\Models\ProductWarehouses::with('warehouse')->where('id_product', $product->id)->where('stock', '>=', $item['qty'])->get();
                if ($pws->isEmpty()) throw new \Exception("Stok tidak mencukupi untuk: " . $product->name);

                $bestWarehouse = $pws->sortByDesc(fn($pw) => (strtolower($pw->warehouse->city ?? '') === strtolower($userCity) ? 100 : 0) + $pw->stock)->first();
                $warehouseGroups[$bestWarehouse->id_warehouse][] = ['product' => $product, 'qty' => $item['qty'], 'price' => $product->price];
            }

            $ordersCreated = [];
            foreach ($warehouseGroups as $warehouseId => $items) {
                // Hitung Ongkir untuk setiap gudang
                $shipRes = $this->fetchUnifiedShipping($warehouseId, $userCity, $userPostalCode, $items);

                $isDropship = $request->boolean('is_dropship', false);
                $orderTotalPriceWithoutShipping = collect($items)->sum(function($item) use ($isDropship) {
                    $price = $item['price'];
                    if ($isDropship && $item['product']->is_dropship_enabled && $item['qty'] >= $item['product']->dropship_min_qty) {
                        if ($item['product']->dropship_discount_type === 'percent') $price -= ($price * ($item['product']->dropship_discount_value / 100));
                        elseif ($item['product']->dropship_discount_type === 'fixed') $price -= $item['product']->dropship_discount_value;
                        $price = max(0, $price);
                    }
                    return $item['qty'] * $price;
                });

                // Bagi rata diskon jika ada lebih dari 1 order (opsional, untuk sekarang kita taruh di order pertama saja)
                $currentDiscount = (count($ordersCreated) === 0) ? ($request->discount_amount ?? 0) : 0;

                $order = Orders::create([
                    'invoice_no' => 'INV-' . date('Ymd') . '-' . rand(1000, 9999), 
                    'user_id' => $userId, 
                    'total_price' => ($orderTotalPriceWithoutShipping - $currentDiscount) + $shipRes['price'], 
                    'address' => $request->address,
                    'payment_method' => $request->payment_method,
                    'status' => 'pending', 
                    'affiliate_id' => $affiliateId, 
                    'id_promotion' => $id_promotion,
                    'discount_amount' => $currentDiscount,
                    'shipping_cost' => $shipRes['price'],
                    'warehouse_id' => $warehouseId,
                    'is_dropship' => $isDropship,
                    'dropshipper_name' => $isDropship ? $request->dropshipper_name : null,
                    'courier_company' => $shipRes['courier'] ?? 'JNE REG'
                ]);

                foreach ($items as $item) {
                    OrderItems::create(['order_id' => $order->id, 'product_id' => $item['product']->id, 'quantity' => $item['qty'], 'price' => $item['price']]);
                    // Hapus dari keranjang
                    Carts::where('user_id', $userId)->where('product_id', $item['product']->id)->delete();
                }

                $ordersCreated[] = $order;
            }

            // Untuk Xendit, kita buat invoice untuk total SEMUA order jika lebih dari satu? 
            // Atau per order? Bisanya per order agar tracking gampang.
            // Namun jika user bayar sekali, kita perlu menggabungkannya.
            // Sederhananya, kita proses order pertama dulu untuk link pembayaran jika banyak, 
            // tapi idealnya xendit mendukung multiple items.
            
            $firstOrder = $ordersCreated[0];
            $totalAmount = collect($ordersCreated)->sum('total_price');

            $paymentUrl = null;
            if ($request->payment_method !== 'cod') {
                $secretKey = env('XENDIT_SECRET_KEY');
                $xenditRes = Http::withHeaders(['Authorization' => 'Basic ' . base64_encode($secretKey . ':')])->post('https://api.xendit.co/v2/invoices', [
                    'external_id' => $firstOrder->invoice_no, // Gunakan invoice pertama sebagai referensi utama
                    'amount' => $totalAmount,
                    'payer_email' => $request->user()->email,
                    'description' => 'Pembayaran Pesanan OKAI (' . count($ordersCreated) . ' Gudang)',
                    'invoice_duration' => 86400,
                    'success_redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/orders',
                    'failure_redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/checkout',
                ]);
                if ($xenditRes->successful()) {
                    $paymentUrl = $xenditRes->json()['invoice_url'];
                    foreach ($ordersCreated as $o) {
                        $o->update(['payment_url' => $paymentUrl]);
                    }
                } else {
                    throw new \Exception("Gagal membuat tagihan Xendit."); 
                }
            } else {
                // Jika COD, kurangi stok langsung agar stok aman (reservasi).
                // Komisi tetap dicatat sebagai pending.
                foreach ($ordersCreated as $o) {
                    $this->reduceStock($o);
                    $this->calculateAffiliateCommission($o);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat!',
                'data' => $firstOrder,
                'payment_url' => $paymentUrl,
                'orders' => $ordersCreated
            ], 201);
        });
    }

    protected function reduceStock($order)
    {
        if ($order->warehouse_id) {
            foreach ($order->items as $item) {
                $pw = \App\Models\ProductWarehouses::where('id_warehouse', $order->warehouse_id)
                    ->where('id_product', $item->product_id)
                    ->first();
                if ($pw && $pw->stock >= $item->quantity) {
                    $pw->decrement('stock', $item->quantity);
                }
            }
        }
    }

    protected function processOrderPaid($order)
    {
        if ($order->status === 'pending') {
            DB::transaction(function () use ($order) {
                $order->status = 'paid';
                $order->save();
                $this->reduceStock($order);
                $this->calculateAffiliateCommission($order);
            });
        }
    }

    public function markAsPaid($id)
    {
        $order = Orders::find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);

        if ($order->status === 'pending') {
            $this->processOrderPaid($order);
            return response()->json(['success' => true, 'message' => 'Pesanan ditandai lunas, stok dikurangi, dan komisi dicatat.']);
        }

        return response()->json(['success' => false, 'message' => 'Hanya pesanan pending yang bisa ditandai lunas.'], 400);
    }

    public function getAvailableWarehouses($id)
    {
        $order = Orders::with('items')->find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);

        $available = [];
        foreach ($order->items as $item) {
            $pws = \App\Models\ProductWarehouses::with('warehouse')
                ->where('id_product', $item->product_id)
                ->where('stock', '>=', $item->quantity)
                ->get();
            
            $available[$item->id] = $pws->map(function($pw) {
                return [
                    'id_warehouse' => $pw->id_warehouse,
                    'name' => $pw->warehouse->name,
                    'stock' => $pw->stock
                ];
            });
        }

        return response()->json(['success' => true, 'data' => $available]);
    }

    public function simulateDelivery($id)
    {
        $order = Orders::with(['items.product', 'affiliate'])->find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);

        $this->processOrderDelivered($order);

        return response()->json(['success' => true, 'message' => 'Simulasi berhasil: Pesanan telah tiba dan komisi diproses.']);
    }

    public function show($id)
    {
        $parts = explode('-', $id);
        $originalId = end($parts);
        $order = Orders::with(['user', 'items.product', 'promotion'])->where('id', $originalId)->orWhere('invoice_no', $id)->first();
        if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        return response()->json(['success' => true, 'data' => $order]);
    }

    protected function processOrderDelivered($order)
    {
        DB::transaction(function () use ($order) {
            $order->status = 'delivered';
            $order->save();

            // 1. Update status komisi menjadi 'approved' (bukan 'completed' yang tidak ada di enum)
            $commissions = \App\Models\AffiliateCommissions::where('order_id', $order->id)->get();
            
            foreach ($commissions as $comm) {
                if ($comm->status !== 'approved') {
                    $comm->status = 'approved';
                    $comm->save();

                    // 2. Otomatis buat WithdrawalRequest untuk affiliator terkait
                    $affiliate = \App\Models\Affiliates::find($comm->affiliate_id);
                    if ($affiliate) {
                        \App\Models\WithdrawalRequest::create([
                            'affiliate_id' => $affiliate->id,
                            'amount' => $comm->commission_amount,
                            'bank_name' => $affiliate->bank_name ?? '-',
                            'account_number' => $affiliate->account_number ?? '-',
                            'account_name' => $affiliate->account_holder_name ?? $affiliate->full_name,
                            'status' => 'pending',
                            'admin_note' => 'Otomatis dari Pesanan ' . $order->invoice_no
                        ]);
                    }
                }
            }
        });
    }

    public function syncTracking($id)
    {
        $order = Orders::find($id);
        if (!$order || !$order->waybill_id) {
            return response()->json(['success' => false, 'message' => 'Waybill ID tidak ditemukan.'], 400);
        }

        // 1. Dapatkan API Key
        $apiKey = null;
        $setting = \App\Models\SystemSetting::first();
        if ($setting && $setting->biteship_api_key) {
            try { $apiKey = \Illuminate\Support\Facades\Crypt::decryptString($setting->biteship_api_key); } catch (\Exception $e) {}
        }
        if (!$apiKey) $apiKey = env('BITESHIP_API_KEY');

        try {
            $response = Http::withHeaders([
                'Authorization' => $apiKey,
            ])->get("https://api.biteship.com/v1/trackings/{$order->waybill_id}");

            if ($response->successful()) {
                $trackingData = $response->json();
                $status = $trackingData['status'] ?? '';

                if ($status === 'delivered') {
                    $this->processOrderDelivered($order);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Status berhasil disinkronisasi.',
                    'status' => $status,
                    'tracking' => $trackingData
                ]);
            } else {
                return response()->json([
                    'success' => false, 
                    'message' => 'Gagal mengambil data dari Biteship.',
                    'details' => $response->json()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    public function xenditWebhook(Request $request)
    {
        $external_id = $request->input('external_id');
        $status = strtoupper($request->input('status', ''));

        if (in_array($status, ['PAID', 'SETTLED'])) {
            // Temukan pesanan utama
            $mainOrder = Orders::where('invoice_no', $external_id)->first();
            
            if ($mainOrder && $mainOrder->status === 'pending') {
                // Cari semua pesanan yang memiliki payment_url yang sama (untuk order yang di-split)
                $orders = Orders::where('payment_url', $mainOrder->payment_url)
                               ->where('status', 'pending')
                               ->get();

                if ($orders->isEmpty()) {
                    $orders = collect([$mainOrder]);
                }

                foreach ($orders as $order) {
                    $this->processOrderPaid($order);
                }
            }
        } elseif (in_array($status, ['EXPIRED'])) {
            $mainOrder = Orders::where('invoice_no', $external_id)->first();
            if ($mainOrder && $mainOrder->status === 'pending') {
                Orders::where('payment_url', $mainOrder->payment_url)
                      ->where('status', 'pending')
                      ->update(['status' => 'cancelled']);
            }
        }
        return response()->json(['success' => true, 'message' => 'Webhook diterima.']);        
    }

    protected function calculateAffiliateCommission($order)
    {
        // Hindari pencatatan ganda
        $exists = \App\Models\AffiliateCommissions::where('order_id', $order->id)->exists();
        if ($exists) {
            return;
        }

        if ($order->affiliate_id) {
            $order->load(['items.product', 'affiliate']);
            $totalCommission = 0;
            $affiliateGlobalRate = $order->affiliate->commission_rate ?? 10;

            foreach ($order->items as $item) {
                $product = $item->product;
                if ($product && $product->is_affiliate_enabled) {
                    if ($product->commission_value > 0) {
                        if ($product->commission_type === 'percent') {
                            $totalCommission += ($item->price * $item->quantity) * ($product->commission_value / 100);
                        } else {
                            $totalCommission += ($product->commission_value * $item->quantity);
                        }
                    } else {
                        $totalCommission += ($item->price * $item->quantity) * ($affiliateGlobalRate / 100);
                    }
                }
            }

            if ($totalCommission > 0) {
                \App\Models\AffiliateCommissions::create([
                    'order_id' => $order->id,
                    'affiliate_id' => $order->affiliate_id,
                    'commission_amount' => $totalCommission,
                    'status' => 'pending'
                ]);
            }
        }
    }

    public function biteshipWebhook(Request $request)
    {
        $event = $request->input('event');
        $status = $request->input('status');
        $waybillId = $request->input('waybill_id');
        $orderIdBiteship = $request->input('order_id');

        // Cari pesanan berdasarkan waybill_id atau invoice_no (order_note)
        $order = Orders::where('waybill_id', $waybillId)->first();
        
        // Fallback: Jika tidak ada waybill_id, coba cari via order_note di payload jika ada
        if (!$order && $request->has('order_note')) {
            $invoiceNo = str_replace('Order ID: ', '', $request->input('order_note'));
            $order = Orders::where('invoice_no', $invoiceNo)->first();
        }

        if ($order) {
            if ($status === 'delivered') {
                $this->processOrderDelivered($order);
            } elseif (in_array($status, ['rejected', 'cancelled', 'returned'])) {
                $order->status = 'cancelled';
                $order->save();
            }
            // Status lain seperti 'pickingUp', 'picked', 'inTransit' bisa ditambahkan jika perlu
        }

        return response()->json(['success' => true, 'message' => 'Biteship Webhook processed.']);
    }

    public function shipManual(Request $request, $id)
    {
        $order = Orders::find($id);
        if (!$order || $order->status !== 'paid') return response()->json(['success' => false, 'message' => 'Gagal memproses.'], 400);
        $request->validate(['awb_number' => 'required|string', 'courier_company' => 'required|string']);
        $order->status = 'shipped'; $order->awb_number = $request->awb_number; $order->courier_company = $request->courier_company; $order->save();
        return response()->json(['success' => true, 'message' => 'Berhasil diperbarui!']);
    }

    public function shipWithBiteship($id, Request $request)
    {
        $order = Orders::with(['user', 'items.product'])->find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        if ($order->status !== 'paid') return response()->json(['success' => false, 'message' => 'Hanya pesanan PAID yang bisa diproses Biteship.'], 400);

        // 1. Dapatkan API Key
        $apiKey = null;
        $setting = \App\Models\SystemSetting::first();
        if ($setting && $setting->biteship_api_key) {
            try { $apiKey = \Illuminate\Support\Facades\Crypt::decryptString($setting->biteship_api_key); } catch (\Exception $e) {}
        }
        if (!$apiKey) $apiKey = env('BITESHIP_API_KEY');
        if (!$apiKey) return response()->json(['success' => false, 'message' => 'API Key Biteship tidak ditemukan.'], 500);

        // 2. Dapatkan Gudang (Asal)
        $warehouse = \App\Models\Warehouses::find($order->warehouse_id);
        if (!$warehouse) return response()->json(['success' => false, 'message' => 'Gudang asal belum ditentukan.'], 400);

        // 3. Persiapkan Data Payload untuk Biteship
        $addressParts = array_map('trim', explode(',', $order->address));
        $destPostalCode = end($addressParts);

        $payload = [
            'shipper_contact_name' => $setting->brand_name ?? 'Okai Store',
            'shipper_contact_phone' => $request->admin_phone ?? '08123456789',
            'shipper_contact_email' => env('MAIL_FROM_ADDRESS', 'admin@okai.com'),
            'shipper_organization' => $setting->brand_name ?? 'Okai Store',
            'origin_contact_name' => $warehouse->name,
            'origin_contact_phone' => $request->admin_phone ?? '08123456789',
            'origin_address' => $warehouse->address,
            'origin_note' => 'Gudang ' . $warehouse->city,
            'origin_postal_code' => (int) $warehouse->postal_code,
            
            'destination_contact_name' => $order->user->name,
            'destination_contact_phone' => $order->user->phone_number ?? '08123456789',
            'destination_contact_email' => $order->user->email,
            'destination_address' => $order->address,
            'destination_postal_code' => (int) $destPostalCode,
            'destination_note' => $order->address_note ?? 'Patokan alamat pembeli',

            'courier_company' => $request->courier_company ?? 'jne',
            'courier_type' => $request->courier_type ?? 'reg',
            'delivery_type' => 'now',
            'order_note' => 'Order ID: ' . $order->invoice_no,
            'items' => collect($order->items)->map(function($item) {
                return [
                    'name' => $item->product->name,
                    'description' => $item->product->description ?? 'Produk Okai',
                    'sku' => $item->product->sku,
                    'value' => (int) $item->price,
                    'quantity' => (int) $item->quantity,
                    'weight' => (int) ($item->product->weight ?? 1000),
                    'length' => (int) ($item->product->length ?? 10),
                    'width' => (int) ($item->product->width ?? 10),
                    'height' => (int) ($item->product->height ?? 10),
                ];
            })->toArray()
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json'
            ])->post('https://api.biteship.com/v1/orders', $payload);

            if ($response->successful()) {
                $resData = $response->json();
                $order->status = 'shipped';
                $order->waybill_id = $resData['courier']['waybill_id'] ?? null;
                $order->courier_company = $resData['courier']['company'] ?? $request->courier_company;
                $order->courier_type = $resData['courier']['type'] ?? $request->courier_type;
                $order->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Pesanan berhasil diserahkan ke Biteship!',
                    'data' => $resData
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Biteship Error: ' . ($response->json()['message'] ?? 'Unknown Error'),
                    'error_from_biteship' => $response->json()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
        }
    }
}
