<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Products;
use App\Models\Orders;
use App\Models\OrderItems;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'super_admin']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'customer']);
        // Role::create(['name' => 'affiliate']);

        $super_admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('admin'),
        ]);

        $super_admin->assignRole('super_admin');

        $customer = User::firstOrCreate(
            ['email' => 'budi.santoso@gmail.com'],
            [
                'name' => 'Budi Santoso',
                'password' => Hash::make('password123'),
            ]
        );
        
        $customer->assignRole('customer');

        // ---------------------------------------------------
        // 2. BUAT DATA PRODUK
        // ---------------------------------------------------
        $product = Products::firstOrCreate(
            ['sku' => 'OKAI-RUN-X1'],
            [
                'name' => 'Susu Kambing Jantan',
                'category' => 'Footwear',
                'description' => 'Kesukaan Adudu',
                'price' => 85000,
                'stock' => 50,
                'warehouse' => 'Gudang Utama (Surabaya)',
                'is_active' => 1,
            ]
        );

        // ---------------------------------------------------
        // 3. BUAT DATA PESANAN (ORDER)
        // ---------------------------------------------------
        // Kita buat pesanan untuk Budi dengan status Delivered (Selesai)
        $order = Orders::create([
            'user_id' => $customer->id,
            'total_price' => $product->price * 1, // Harga total (Asumsi beli 1)
            'status' => 'Delivered', 
        ]);

        // ---------------------------------------------------
        // 4. BUAT RINCIAN BARANG DALAM PESANAN (ORDER ITEM)
        // ---------------------------------------------------
        OrderItems::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price, // Merekam harga saat itu agar jika harga produk naik, nota ini tidak berubah
        ]);
        
        $this->call(AffiliateSeeder::class);
    }
}
