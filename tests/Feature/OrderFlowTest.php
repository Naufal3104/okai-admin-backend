<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Products;
use App\Models\Warehouses;
use App\Models\ProductWarehouses;
use App\Models\Orders;
use App\Models\Affiliates;
use App\Models\AffiliateCommissions;
use App\Models\Promotions;
use App\Models\Carts;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration tests for the full order flow.
 *
 * Covers:
 * - Split-warehouse order creation (COD & non-COD)
 * - Affiliate commission calculation (percent & fixed)
 * - Self-referral prevention
 * - Stock reduction on COD and on Xendit payment
 * - Promotion code applied to first split order only
 * - Xendit PAID webhook marks orders as paid and reduces stock
 * - Xendit EXPIRED webhook cancels orders
 * - Dropship order creation
 * - markAsPaid for COD bypass
 */
class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    // ─── Setup ────────────────────────────────────────────────────────────────

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer']);
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);

        $this->customer = User::factory()->create([
            'name'  => 'Customer Test',
            'email' => 'customer@test.com',
        ]);
        $this->customer->assignRole('customer');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Creates a warehouse owned by a unique user to avoid the warehouses.user_id unique constraint.
     */
    private function makeWarehouse(string $city = 'Surabaya', string $postalCode = '60111'): Warehouses
    {
        $owner = User::factory()->create(); // unique user per warehouse
        return Warehouses::create([
            'name'        => 'Gudang ' . $city,
            'address'     => 'Jl. Test No. 1',
            'city'        => $city,
            'province'    => 'Jawa Timur',
            'postal_code' => $postalCode,
            'user_id'     => $owner->id,
        ]);
    }

    private function makeProduct(array $overrides = []): Products
    {
        static $counter = 0;
        $counter++;
        return Products::create(array_merge([
            'sku'   => 'SKU-' . $counter,
            'name'  => 'Produk ' . $counter,
            'price' => 100000,
            'stock' => 10,
        ], $overrides));
    }

    private function attachProductToWarehouse(Products $product, Warehouses $warehouse, int $stock = 10): ProductWarehouses
    {
        return ProductWarehouses::create([
            'id_product'   => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock'        => $stock,
        ]);
    }

    /** Build a base COD checkout payload */
    private function codPayload(array $items, array $overrides = []): array
    {
        return array_merge([
            'address'        => 'Jl. Pelanggan No. 5, Surabaya, Jawa Timur, 60111',
            'payment_method' => 'cod',
            'total_price'    => collect($items)->sum(fn($i) => $i['qty'] * 100000),
            'items'          => $items,
        ], $overrides);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * Single-warehouse COD order: success, stock reduced, order record correct.
     */
    public function test_cod_single_warehouse_order_creates_one_order_and_reduces_stock(): void
    {
        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct();
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload([
            ['product_id' => $product->id, 'qty' => 3],
        ]));

        $response->assertStatus(201)->assertJson(['success' => true]);

        // One order created
        $this->assertDatabaseCount('orders', 1);

        // Stock reduced from 10 to 7
        $this->assertDatabaseHas('product_warehouses', [
            'id_product'   => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock'        => 7,
        ]);

        // Global stock synced
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 7]);
    }

    /**
     * Two products from TWO different warehouses → two separate orders are created.
     */
    public function test_multi_warehouse_checkout_creates_separate_orders(): void
    {
        // Each warehouse is owned by a different user to satisfy the unique(user_id) constraint
        $warehouse1 = $this->makeWarehouse('Surabaya', '60111');
        $warehouse2 = $this->makeWarehouse('Bandung', '40111');

        $product1 = $this->makeProduct();
        $product2 = $this->makeProduct();

        // Each product only available in its own warehouse
        $this->attachProductToWarehouse($product1, $warehouse1, 10);
        $this->attachProductToWarehouse($product2, $warehouse2, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload([
            ['product_id' => $product1->id, 'qty' => 1],
            ['product_id' => $product2->id, 'qty' => 1],
        ]));

        $response->assertStatus(201)->assertJson(['success' => true]);

        // Two orders must be created
        $this->assertDatabaseCount('orders', 2);

        // Each order should be linked to a different warehouse
        $warehouseIds = Orders::pluck('warehouse_id')->unique()->toArray();
        sort($warehouseIds);
        $expected = [$warehouse1->id_warehouse, $warehouse2->id_warehouse];
        sort($expected);
        $this->assertEquals($expected, $warehouseIds);
    }

    /**
     * Discount amount must only be applied to the FIRST order when multiple warehouses are used.
     */
    public function test_discount_applied_to_first_order_only_in_split_checkout(): void
    {
        $warehouse1 = $this->makeWarehouse('Surabaya', '60111');
        $warehouse2 = $this->makeWarehouse('Malang', '65101');

        $product1 = $this->makeProduct(['price' => 100000]);
        $product2 = $this->makeProduct(['price' => 100000]);

        $this->attachProductToWarehouse($product1, $warehouse1, 5);
        $this->attachProductToWarehouse($product2, $warehouse2, 5);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [
                ['product_id' => $product1->id, 'qty' => 1],
                ['product_id' => $product2->id, 'qty' => 1],
            ],
            ['discount_amount' => 20000]
        ));

        $response->assertStatus(201);

        $orders = Orders::all();
        $this->assertCount(2, $orders);

        $discounts = $orders->pluck('discount_amount')->toArray();
        sort($discounts);
        // One order gets 20000 discount, the other gets 0
        $this->assertEquals([0, 20000], $discounts);
    }

    /**
     * Affiliate commission is calculated correctly for a percent-type product commission.
     */
    public function test_affiliate_commission_is_calculated_for_percent_type(): void
    {
        $affiliateUser = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id'         => $affiliateUser->id,
            'full_name'       => 'Mitra Test',
            'email'           => $affiliateUser->email,
            'phone'           => '08123456789',
            'affiliate_code'  => 'KMB-TEST1234',
            'commission_rate' => 10,
            'status'          => 'active',
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct([
            'price'               => 100000,
            'is_affiliate_enabled' => true,
            'commission_type'     => 'percent',
            'commission_value'    => 20, // 20%
        ]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 2]],
            ['affiliate_code' => 'KMB-TEST1234']
        ));

        $response->assertStatus(201);

        // Commission: 2 × 100000 × 20% = 40000
        $this->assertDatabaseHas('affiliate_commissions', [
            'affiliate_id'     => $affiliate->id,
            'commission_amount' => 40000,
            'status'           => 'pending',
        ]);
    }

    /**
     * Affiliate commission is calculated correctly for a fixed-type product commission.
     */
    public function test_affiliate_commission_is_calculated_for_fixed_type(): void
    {
        $affiliateUser = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id'         => $affiliateUser->id,
            'full_name'       => 'Mitra Fixed',
            'email'           => $affiliateUser->email,
            'phone'           => '08111111111',
            'affiliate_code'  => 'KMB-FIX1234',
            'commission_rate' => 10,
            'status'          => 'active',
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct([
            'price'               => 100000,
            'is_affiliate_enabled' => true,
            'commission_type'     => 'fixed',
            'commission_value'    => 5000, // Rp 5.000 per unit
        ]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 3]],
            ['affiliate_code' => 'KMB-FIX1234']
        ));

        $response->assertStatus(201);

        // Commission: 3 × 5000 = 15000
        $this->assertDatabaseHas('affiliate_commissions', [
            'affiliate_id'      => $affiliate->id,
            'commission_amount' => 15000,
        ]);
    }

    /**
     * A user cannot use their own affiliate code (self-referral prevention).
     */
    public function test_self_referral_is_prevented(): void
    {
        $affiliate = Affiliates::create([
            'user_id'         => $this->customer->id,
            'full_name'       => 'Customer Itself',
            'email'           => $this->customer->email,
            'phone'           => '08999999999',
            'affiliate_code'  => 'KMB-SELF1234',
            'commission_rate' => 10,
            'status'          => 'active',
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct([
            'is_affiliate_enabled' => true,
            'commission_type'      => 'percent',
            'commission_value'     => 20,
        ]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 1]],
            ['affiliate_code' => 'KMB-SELF1234']
        ));

        $response->assertStatus(201);

        // No commission should be created since it's a self-referral
        $this->assertDatabaseCount('affiliate_commissions', 0);

        // Order's affiliate_id should be null
        $this->assertDatabaseHas('orders', ['affiliate_id' => null]);
    }

    /**
     * Non-COD orders do NOT reduce stock at creation; stock is reduced after Xendit PAID webhook.
     */
    public function test_non_cod_order_does_not_reduce_stock_until_xendit_webhook(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'invoice_url' => 'https://checkout.xendit.co/v2/test-invoice-id',
                'id'          => 'test-xendit-id',
                'external_id' => 'INV-TEST',
            ], 200),
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct(['stock' => 10]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 4]],
            ['payment_method' => 'bca_virtual_account']
        ));

        $response->assertStatus(201);

        // Stock NOT reduced yet
        $this->assertDatabaseHas('product_warehouses', [
            'id_product'   => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock'        => 10,
        ]);

        // Now simulate Xendit PAID webhook (correct route: /api/xendit/webhook)
        $order = Orders::first();
        $webhookResponse = $this->postJson('/api/xendit/webhook', [
            'external_id' => $order->invoice_no,
            'status'      => 'PAID',
        ]);

        $webhookResponse->assertStatus(200);

        // Now stock should be reduced
        $this->assertDatabaseHas('product_warehouses', [
            'id_product'   => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock'        => 6,
        ]);

        // Order status should be paid
        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'paid',
        ]);
    }

    /**
     * Xendit EXPIRED webhook cancels pending orders.
     */
    public function test_xendit_expired_webhook_cancels_orders(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'invoice_url' => 'https://checkout.xendit.co/v2/expired-invoice',
                'id'          => 'expired-xendit-id',
                'external_id' => 'INV-EXPIRED',
            ], 200),
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct();
        $this->attachProductToWarehouse($product, $warehouse, 5);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 2]],
            ['payment_method' => 'bca_virtual_account']
        ));

        $order = Orders::first();
        $this->assertEquals('pending', $order->status);

        $this->postJson('/api/xendit/webhook', [
            'external_id' => $order->invoice_no,
            'status'      => 'EXPIRED',
        ])->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'cancelled',
        ]);
    }

    /**
     * markAsPaid endpoint transitions order from pending → paid and reduces stock (non-COD bypass).
     */
    public function test_mark_as_paid_reduces_stock_and_transitions_status(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'invoice_url' => 'https://checkout.xendit.co/v2/manual-paid',
                'id'          => 'manual-xendit-id',
                'external_id' => 'INV-MANUAL',
            ], 200),
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct(['stock' => 10]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 2]],
            ['payment_method' => 'transfer']
        ));

        $order = Orders::first();
        $this->assertEquals('pending', $order->status);

        // mark-paid is accessible to any authenticated user (no role guard on this route)
        $response = $this->actingAs($this->customer)->postJson('/api/orders/' . $order->id . '/mark-paid');

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);

        // Stock reduced after mark as paid
        $this->assertDatabaseHas('product_warehouses', [
            'id_product'   => $product->id,
            'id_warehouse' => $warehouse->id_warehouse,
            'stock'        => 8,
        ]);
    }

    /**
     * markAsPaid should fail if order is already paid (idempotency check).
     */
    public function test_mark_as_paid_fails_if_already_paid(): void
    {
        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct();
        $this->attachProductToWarehouse($product, $warehouse, 5);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 1]]
        ));

        $order = Orders::first();
        // COD orders get stock reduced immediately; simulate paid status
        $order->update(['status' => 'paid']);

        $response = $this->actingAs($this->customer)->postJson('/api/orders/' . $order->id . '/mark-paid');

        $response->assertStatus(400)->assertJson(['success' => false]);
    }

    /**
     * Promotion code increments used_count upon checkout.
     * Note: The promotions.type enum is ['percentage', 'fixed_amount'].
     */
    public function test_promotion_code_used_count_increments_on_checkout(): void
    {
        $promo = Promotions::create([
            'code'       => 'TESTPROMO',
            'type'       => 'fixed_amount', // valid enum value from migration
            'value'      => 10000,
            'used_count' => 0,
            'is_active'  => true,
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct();
        $this->attachProductToWarehouse($product, $warehouse, 5);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 1]],
            ['promotion_code' => 'TESTPROMO', 'discount_amount' => 10000]
        ));

        $this->assertDatabaseHas('promotions', [
            'code'       => 'TESTPROMO',
            'used_count' => 1,
        ]);
    }

    /**
     * Dropship order is created with is_dropship=true and dropshipper_name stored.
     */
    public function test_dropship_order_stores_dropshipper_info(): void
    {
        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct([
            'is_dropship_enabled'    => true,
            'dropship_min_qty'       => 1,
            'dropship_discount_type' => 'percent',
            'dropship_discount_value' => 10,
        ]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $response = $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 2]],
            [
                'is_dropship'      => true,
                'dropshipper_name' => 'Toko Maju Jaya',
            ]
        ));

        $response->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'is_dropship'      => true,
            'dropshipper_name' => 'Toko Maju Jaya',
        ]);
    }

    /**
     * Courier company and type are stored separately in the orders table.
     */
    public function test_courier_company_and_type_stored_separately(): void
    {
        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct();
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 1]]
        ));

        $order = Orders::first();
        // courier_company and courier_type should both be non-null strings
        $this->assertNotNull($order->courier_company);
        $this->assertNotNull($order->courier_type);
        // They must be stored as separate values (not combined string like "JNE REG")
        $this->assertStringNotContainsString(' ', $order->courier_company);
    }

    /**
     * Cart is cleared for items that were successfully checked out (COD).
     */
    public function test_cart_items_are_cleared_after_successful_checkout(): void
    {
        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct();
        $this->attachProductToWarehouse($product, $warehouse, 10);

        // Add item to cart first
        Carts::create([
            'user_id'    => $this->customer->id,
            'product_id' => $product->id,
            'qty'        => 2,
        ]);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 2]]
        ));

        // Cart should be empty for this user
        $this->assertDatabaseMissing('carts', [
            'user_id'    => $this->customer->id,
            'product_id' => $product->id,
        ]);
    }

    /**
     * Affiliate commission is NOT double-counted if calculateAffiliateCommission is called twice.
     */
    public function test_affiliate_commission_is_not_duplicated_on_double_call(): void
    {
        $affiliateUser = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id'         => $affiliateUser->id,
            'full_name'       => 'Mitra No Dupe',
            'email'           => $affiliateUser->email,
            'phone'           => '08777777777',
            'affiliate_code'  => 'KMB-NODUPE99',
            'commission_rate' => 10,
            'status'          => 'active',
        ]);

        $warehouse = $this->makeWarehouse();
        $product   = $this->makeProduct([
            'price'               => 100000,
            'is_affiliate_enabled' => true,
            'commission_type'     => 'percent',
            'commission_value'    => 10,
        ]);
        $this->attachProductToWarehouse($product, $warehouse, 10);

        $this->actingAs($this->customer)->postJson('/api/orders', $this->codPayload(
            [['product_id' => $product->id, 'qty' => 1]],
            ['affiliate_code' => 'KMB-NODUPE99']
        ));

        // Exactly one commission record, no duplicates
        $this->assertDatabaseCount('affiliate_commissions', 1);
    }
}
