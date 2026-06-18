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
                                $order->status = 'paid';
                                $order->save();
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

            $firstWarehouseId = array_key_first($warehouseGroups);
            $itemsToProcess = $warehouseGroups[$firstWarehouseId];
            $processedProductIds = collect($itemsToProcess)->pluck('product.id')->toArray();

            // Hitung Ongkir dengan logika Unified Fallback
            $shipRes = $this->fetchUnifiedShipping($firstWarehouseId, $userCity, $userPostalCode, $itemsToProcess);

            $isDropship = $request->boolean('is_dropship', false);
            $orderTotalPriceWithoutShipping = collect($itemsToProcess)->sum(function($item) use ($isDropship) {
                $price = $item['price'];
                if ($isDropship && $item['product']->is_dropship_enabled && $item['qty'] >= $item['product']->dropship_min_qty) {
                    if ($item['product']->dropship_discount_type === 'percent') $price -= ($price * ($item['product']->dropship_discount_value / 100));
                    elseif ($item['product']->dropship_discount_type === 'fixed') $price -= $item['product']->dropship_discount_value;
                    $price = max(0, $price);
                }
                return $item['qty'] * $price;
            });

            $order = Orders::create([
                'invoice_no' => 'INV-' . date('Ymd') . '-' . rand(1000, 9999), 
                'user_id' => $userId, 
                'total_price' => $orderTotalPriceWithoutShipping + $shipRes['price'], 
                'address' => $request->address,
                'payment_method' => $request->payment_method,
                'status' => 'pending', 
                'affiliate_id' => $affiliateId, 
                'id_promotion' => $id_promotion,
                'warehouse_id' => $firstWarehouseId,
                'is_dropship' => $isDropship,
                'dropshipper_name' => $isDropship ? $request->dropshipper_name : null
            ]);

            foreach ($itemsToProcess as $item) {
                OrderItems::create(['order_id' => $order->id, 'product_id' => $item['product']->id, 'quantity' => $item['qty'], 'price' => $item['price']]);
            }

            Carts::where('user_id', $userId)->whereIn('id_product', $processedProductIds)->delete();

            $paymentUrl = null;
            if ($request->payment_method !== 'cod') {
                $secretKey = env('XENDIT_SECRET_KEY');
                $xenditRes = Http::withHeaders(['Authorization' => 'Basic ' . base64_encode($secretKey . ':')])->post('https://api.xendit.co/v2/invoices', [
                    'external_id' => $order->invoice_no,
                    'amount' => $order->total_price,
                    'payer_email' => $request->user()->email,
                    'description' => 'Pembayaran Pesanan ' . $order->invoice_no,
                    'invoice_duration' => 86400,
                    'success_redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/orders',
                    'failure_redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/checkout',
                ]);
                if ($xenditRes->successful()) {
                    $paymentUrl = $xenditRes->json()['invoice_url'];
                    $order->update(['payment_url' => $paymentUrl]);
                } else {
                    throw new \Exception("Gagal membuat tagihan Xendit."); 
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($warehouseGroups) > 1 ? 'Pesanan berhasil! Ada produk beda gudang tertinggal di keranjang.' : 'Pesanan berhasil dibuat!',
                'data' => $order,
                'payment_url' => $paymentUrl,
                'has_remaining_items' => count($warehouseGroups) > 1
            ], 201);
        });
    }

    public function show($id)
    {
        $parts = explode('-', $id);
        $originalId = end($parts);
        $order = Orders::with(['user', 'items.product'])->where('id', $originalId)->orWhere('invoice_no', $id)->first();
        if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        return response()->json(['success' => true, 'data' => $order]);
    }

    public function xenditWebhook(Request $request)
    {
        $external_id = $request->input('external_id');
        $status = strtoupper($request->input('status', ''));
        if (in_array($status, ['PAID', 'SETTLED'])) {
            $order = Orders::with('items')->where('invoice_no', $external_id)->first();
            if ($order && $order->status === 'pending') {
                DB::transaction(function () use ($order) {
                    $order->status = 'paid'; $order->save();
                    if ($order->warehouse_id) {
                        foreach ($order->items as $item) {
                            $pw = \App\Models\ProductWarehouses::where('id_warehouse', $order->warehouse_id)->where('id_product', $item->product_id)->first();
                            if ($pw && $pw->stock >= $item->quantity) $pw->decrement('stock', $item->quantity);
                        }
                    }
                });
            }
        } elseif (in_array($status, ['EXPIRED'])) {
            $order = Orders::where('invoice_no', $external_id)->first();
            if ($order && $order->status === 'pending') { $order->status = 'cancelled'; $order->save(); }
        }
        return response()->json(['success' => true, 'message' => 'Webhook diterima.']);        
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
        $order->status = 'shipped'; $order->save();
        return response()->json(['success' => true, 'message' => 'Berhasil dikirim!']);
    }
}
