<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouses;
use App\Models\ProductWarehouses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
{
    // 1. Ambil Semua Data Gudang (Index)
    public function index(Request $request)
    {
        $user = auth('sanctum')->user();

        $query = Warehouses::query()->with('user');

        if ($user && $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }

        $warehouses = $query->orderBy('id_warehouse', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $warehouses
        ], 200);
    }

    // 2. Simpan Gudang Baru (Store)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'user_id' => 'nullable|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $warehouse = Warehouses::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Gudang berhasil ditambahkan!',
            'data' => $warehouse
        ], 201);
    }

    // 3. Tampilkan Detail Satu Gudang & Produknya (Show)
    public function show($id)
    {
        $warehouse = Warehouses::with('productStocks.product')->find($id);

        if (!$warehouse) {
            return response()->json(['success' => false, 'message' => 'Gudang tidak ditemukan'], 404);
        }

        // Format datanya agar lebih rapi untuk frontend
        $formattedWarehouse = [
            'id_warehouse' => $warehouse->id_warehouse,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'city' => $warehouse->city,
            'province' => $warehouse->province,
            'postal_code' => $warehouse->postal_code,
            'products' => $warehouse->productStocks->map(function ($stock) {
                return [
                    'id_product_warehouse' => $stock->id,
                    'id_product' => $stock->id_product,
                    'product_name' => $stock->product->name ?? 'Unknown',
                    'product_sku' => $stock->product->sku ?? 'Unknown',
                    'stock' => $stock->stock,
                ];
            })
        ];

        return response()->json([
            'success' => true,
            'data' => $formattedWarehouse
        ], 200);
    }

    // 4. Update Gudang (Update)
    public function update(Request $request, $id)
    {
        $warehouse = Warehouses::find($id);

        if (!$warehouse) {
            return response()->json(['success' => false, 'message' => 'Gudang tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'user_id' => 'nullable|exists:users,id|unique:warehouses,user_id,' . $id . ',id_warehouse'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $warehouse->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Gudang berhasil diperbarui!',
            'data' => $warehouse
        ], 200);
    }

    // 5. Hapus Gudang (Destroy)
    public function destroy($id)
    {
        $warehouse = Warehouses::find($id);

        if (!$warehouse) {
            return response()->json(['success' => false, 'message' => 'Gudang tidak ditemukan'], 404);
        }

        $warehouse->delete();

        return response()->json([
            'success' => true,
            'message' => 'Gudang berhasil dihapus!'
        ], 200);
    }

    // 6. Atur Stok Produk di Gudang
    public function updateProductStock(Request $request, $id_warehouse)
    {
        $warehouse = Warehouses::find($id_warehouse);

        if (!$warehouse) {
            return response()->json(['success' => false, 'message' => 'Gudang tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'id_product' => 'required|exists:products,id',
            'stock' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $id_product = $request->input('id_product');
        $stock = $request->input('stock');

        // Cek apakah produk sudah ada di gudang ini
        $productWarehouse = ProductWarehouses::where('id_warehouse', $id_warehouse)
            ->where('id_product', $id_product)
            ->first();

        if ($productWarehouse) {
            // Update stok
            $productWarehouse->update(['stock' => $stock]);
            $message = 'Stok produk berhasil diperbarui di gudang.';
        } else {
            // Tambah produk ke gudang
            ProductWarehouses::create([
                'id_warehouse' => $id_warehouse,
                'id_product' => $id_product,
                'stock' => $stock
            ]);
            $message = 'Produk berhasil ditambahkan ke gudang dengan stok awal.';
        }

        // Sync global product stock
        $totalStock = ProductWarehouses::where('id_product', $id_product)->sum('stock');
        \App\Models\Products::where('id', $id_product)->update(['stock' => $totalStock]);

        return response()->json([
            'success' => true,
            'message' => $message
        ]);
    }
}
