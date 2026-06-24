<?php

namespace Tests\Feature;

use App\Models\AffiliateCommissions;
use App\Models\Affiliates;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test updateBankInfo validation failures.
     */
    public function test_update_bank_info_validation_fails(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)
            ->postJson('/api/user/affiliate-bank', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bank_name', 'account_number', 'account_holder_name']);
    }

    /**
     * Test updateBankInfo fails if the user is not an affiliate.
     */
    public function test_update_bank_info_fails_if_not_affiliate(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/user/affiliate-bank', [
                'bank_name' => 'BCA',
                'account_number' => '123456789',
                'account_holder_name' => 'John Doe',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Anda bukan affiliate.'
            ]);
    }

    /**
     * Test updateBankInfo succeeds if the user is an active affiliate.
     */
    public function test_update_bank_info_succeeds(): void
    {
        $user = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '08123456789',
            'status' => 'active',
            'commission_rate' => 15,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/user/affiliate-bank', [
                'bank_name' => 'BCA',
                'account_number' => '123456789',
                'account_holder_name' => 'John Doe',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Informasi rekening bank berhasil diperbarui.'
            ]);

        $this->assertDatabaseHas('affiliates', [
            'id' => $affiliate->id,
            'bank_name' => 'BCA',
            'account_number' => '123456789',
            'account_holder_name' => 'John Doe',
        ]);
    }

    /**
     * Test markWithdrawalAsPaid fails if withdrawal not found.
     */
    public function test_mark_withdrawal_as_paid_fails_if_not_found(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)
            ->postJson('/api/affiliate/withdrawals/99999/pay');

        $response->assertStatus(404);
    }

    /**
     * Test markWithdrawalAsPaid fails if status is not approved.
     */
    public function test_mark_withdrawal_as_paid_fails_if_status_is_pending(): void
    {
        $user = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '08123456789',
            'status' => 'active',
            'commission_rate' => 15,
        ]);

        $withdrawal = WithdrawalRequest::create([
            'affiliate_id' => $affiliate->id,
            'amount' => 100000,
            'bank_name' => 'BCA',
            'account_number' => '123456789',
            'account_name' => 'John Doe',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/affiliate/withdrawals/{$withdrawal->id}/pay");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Hanya penarikan berstatus approved yang bisa ditandai paid.'
            ]);
    }

    /**
     * Test markWithdrawalAsPaid fails if bank info in affiliates table is incomplete.
     */
    public function test_mark_withdrawal_as_paid_fails_if_bank_info_incomplete(): void
    {
        $user = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '08123456789',
            'status' => 'active',
            'commission_rate' => 15,
            'bank_name' => null, // missing bank info
            'account_number' => null,
            'account_holder_name' => null,
        ]);

        $withdrawal = WithdrawalRequest::create([
            'affiliate_id' => $affiliate->id,
            'amount' => 100000,
            'bank_name' => 'BCA',
            'account_number' => '123456789',
            'account_name' => 'John Doe',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/affiliate/withdrawals/{$withdrawal->id}/pay");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Data rekening mitra belum lengkap! Harap lengkapi Nama Bank, Nomor Rekening, dan Nama Pemilik Rekening di profil mitra terlebih dahulu.'
            ]);
    }

    /**
     * Test markWithdrawalAsPaid succeeds and updates statuses correctly.
     */
    public function test_mark_withdrawal_as_paid_succeeds(): void
    {
        $user = User::factory()->create();
        $affiliate = Affiliates::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '08123456789',
            'status' => 'active',
            'commission_rate' => 15,
            'bank_name' => 'BCA',
            'account_number' => '123456789',
            'account_holder_name' => 'John Doe',
        ]);

        $withdrawal = WithdrawalRequest::create([
            'affiliate_id' => $affiliate->id,
            'amount' => 100000,
            'bank_name' => 'BCA',
            'account_number' => '123456789',
            'account_name' => 'John Doe',
            'status' => 'approved',
            'admin_note' => 'Pencairan manual',
        ]);

        $order = \App\Models\Orders::create([
            'user_id' => $user->id,
            'affiliate_id' => $affiliate->id,
            'total_price' => 200000,
            'status' => 'delivered',
        ]);

        $commission = AffiliateCommissions::create([
            'order_id' => $order->id,
            'affiliate_id' => $affiliate->id,
            'commission_amount' => 100000,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/affiliate/withdrawals/{$withdrawal->id}/pay");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Status pencairan berhasil diubah menjadi PAID.'
            ]);

        $this->assertDatabaseHas('withdrawal_requests', [
            'id' => $withdrawal->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('affiliate_commissions', [
            'id' => $commission->id,
            'status' => 'paid',
        ]);
    }
}
