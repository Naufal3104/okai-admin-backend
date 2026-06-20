<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Products;
use App\Models\Warehouses;
use App\Models\ProductWarehouses;
use App\Models\Carts;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create Spatie roles for testing
        Role::firstOrCreate(['name' => 'customer']);
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
    }

    /**
     * Test cart creation fails if quantity exceeds available warehouse stock.
     */
    public function test_cart_fails_if_quantity_exceeds_stock(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        
        $product = Products::create([
            'sku' => 'PROD-001',
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 5,
        ]);
        
        $warehouse = Warehouses::create([
            'name' => 'Gudang Utama',
            'address' => 'Jl. Test No. 1',
            'city' => 'Surabaya',
            'province' => 'Jawa Timur',
            'postal_code' => '60111',
            'user_id' => $user->id,
        ]);

        ProductWarehouses::create([
            'id_product' => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock' => 5,
        ]);

        // Attempt to add quantity = 10 to cart (stock in warehouse is only 5)
        $response = $this->actingAs($user)
            ->postJson('/api/carts', [
                'product_id' => $product->id,
                'qty' => 10,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Stok produk tidak mencukupi.'
            ]);
    }

    /**
     * Test cart creation fails if quantity is invalid (negative or zero).
     */
    public function test_cart_fails_if_quantity_is_invalid(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        
        $product = Products::create([
            'sku' => 'PROD-001',
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 5,
        ]);

        // Attempt to add quantity = -5 (negative) to cart
        $response = $this->actingAs($user)
            ->postJson('/api/carts', [
                'product_id' => $product->id,
                'qty' => -5,
            ]);

        $response->assertStatus(422); // Validation error (qty min:1)
    }

    /**
     * Test checkout fails if quantity exceeds available warehouse stock.
     */
    public function test_checkout_fails_if_quantity_exceeds_stock(): void
    {
        $user = User::factory()->create(['address' => 'Jl. Test No. 1, Surabaya, Jawa Timur, 60111']);
        $user->assignRole('customer');
        
        $product = Products::create([
            'sku' => 'PROD-001',
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 5,
        ]);

        $warehouse = Warehouses::create([
            'name' => 'Gudang Utama',
            'address' => 'Jl. Test No. 1',
            'city' => 'Surabaya',
            'province' => 'Jawa Timur',
            'postal_code' => '60111',
            'user_id' => $user->id,
        ]);

        ProductWarehouses::create([
            'id_product' => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock' => 5,
        ]);

        // Attempt to checkout with 10 items (more than 5 in stock)
        $response = $this->actingAs($user)
            ->postJson('/api/orders', [
                'address' => 'Jl. Test No. 1, Surabaya, Jawa Timur, 60111',
                'payment_method' => 'cod',
                'total_price' => 125000,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'qty' => 10,
                    ]
                ]
            ]);

        $response->assertStatus(500); // Exception is thrown due to insufficient stock
    }

    /**
     * Test checkout fails if inputs are missing or invalid.
     */
    public function test_checkout_fails_if_inputs_missing(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        // Attempt to checkout with missing address and payment method
        $response = $this->actingAs($user)
            ->postJson('/api/orders', [
                'total_price' => 10000,
                'items' => []
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address', 'payment_method']);
    }

    /**
     * Test that system settings access is restricted strictly to email admin@gmail.com.
     */
    public function test_system_settings_restricted_to_admin_gmail(): void
    {
        // User with super_admin role but other email
        $user = User::factory()->create([
            'email' => 'other_superadmin@gmail.com',
        ]);
        $user->assignRole('super_admin');

        // Attempt to read system settings
        $response = $this->actingAs($user)
            ->getJson('/api/system-settings');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized'
            ]);

        // Superadmin with email admin@gmail.com should succeed
        $allowedUser = User::factory()->create([
            'email' => 'admin@gmail.com',
        ]);
        $allowedUser->assignRole('super_admin');

        $responseAllowed = $this->actingAs($allowedUser)
            ->getJson('/api/system-settings');

        $responseAllowed->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);
    }
}
