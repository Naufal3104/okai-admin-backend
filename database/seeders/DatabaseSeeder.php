<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Products;
use App\Models\Orders;
use App\Models\OrderItems;
use App\Models\Warehouses;
use App\Models\ProductWarehouses;
use App\Models\SystemSetting;
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

        // 1. ROLES & USERS
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'customer']);

        $super_admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin'),
            ]
        );
        if (!$super_admin->hasRole('super_admin')) $super_admin->assignRole('super_admin');

        $admin_gudang = User::firstOrCreate(
            ['email' => 'admin.gudang@gmail.com'],
            [
                'name' => 'Admin Gudang Surabaya',
                'password' => Hash::make('admin123'),
            ]
        );
        if (!$admin_gudang->hasRole('admin')) $admin_gudang->assignRole('admin');

        $customer = User::firstOrCreate(
            ['email' => 'budi.santoso@gmail.com'],
            [
                'name' => 'Budi Santoso',
                'password' => Hash::make('password123'),
                'phone_number' => '081234567890',
                'address' => 'Jl. Sudirman No 10, Kebayoran Baru, Jakarta Selatan, DKI Jakarta, 12160'
            ]
        );
        if (!$customer->hasRole('customer')) $customer->assignRole('customer');

        // 2. SYSTEM SETTINGS
        SystemSetting::firstOrCreate(
            ['id' => 1],
            [
                'brand_name' => 'Okai Store',
                'brand_logo' => 'https://ui-avatars.com/api/?name=Okai&background=E65100&color=fff',
                'maintenance_mode' => false,
                'google_redirect_uri' => 'http://localhost:8000/auth/google/callback',
            ]
        );

        // 3. WAREHOUSES (Assigned to admin_gudang)
        $warehouse = Warehouses::firstOrCreate(
            ['name' => 'Gudang Surabaya'],
            [
                'address' => 'Jl. Rungkut no.1',
                'city' => 'Surabaya',
                'province' => 'Jawa Timur',
                'postal_code' => '60293',
                'user_id' => $admin_gudang->id
            ]
        );

        // 4. PRODUCTS (With Dropship & Affiliate config)
        $product = Products::firstOrCreate(
            ['sku' => 'OKAI-SUSU-01'],
            [
                'name' => 'Susu Kambing Etawa Premium',
                'category' => 'Food & Beverage',
                'description' => 'Susu kambing murni berkualitas tinggi yang kaya akan manfaat kesehatan.',
                'price' => 85000,
                'stock' => 0, // Stock total normally aggregated
                'warehouse' => 'Gudang Surabaya', // Legacy field if any
                'is_active' => 1,
                'is_affiliate_enabled' => 1,
                'commission_type' => 'percent',
                'commission_value' => 10, // 10%
                'is_dropship_enabled' => 1,
                'dropship_min_qty' => 5,
                'dropship_discount_type' => 'fixed',
                'dropship_discount_value' => 5000, // Rp 5.000 off per item
            ]
        );

        // 5. PRODUCT WAREHOUSE STOCK
        ProductWarehouses::updateOrCreate(
            ['id_product' => $product->id, 'id_warehouse' => $warehouse->id_warehouse],
            ['stock' => 100]
        );

        // 6. ORDERS (Dummy data)
        $order = Orders::create([
            'invoice_no' => 'INV-20260616-0001',
            'user_id' => $customer->id,
            'total_price' => $product->price + 25000, 
            'address' => $customer->address,
            'payment_method' => 'transfer_bank',
            'status' => 'pending',
            'warehouse_id' => $warehouse->id_warehouse
        ]);

        OrderItems::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
        ]);

        // Run other seeders
        if (class_exists(AffiliateSeeder::class)) {
            $this->call(AffiliateSeeder::class);
        }
    }
}