<?php

namespace Tests\Feature\Wallet;

use App\Models\Deposit;
use App\Models\Role;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DepositWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function fundingWallet($user): Wallet
    {
        return Wallet::where(['user_id' => $user->id, 'currency' => 'USDT', 'wallet_type' => 'funding'])->firstOrFail();
    }

    public function test_deposit_is_credited_only_after_staff_approval(): void
    {
        $user = $this->trader();
        $this->actingAs($user)->postJson('/api/v1/wallet/deposits', ['currency' => 'USDT', 'amount' => '250'], $this->idempotencyHeaders())->assertCreated();
        $this->assertSame('0.000000000000000000', $this->fundingWallet($user)->balance);

        $manager = $this->staff(Role::MANAGER);
        $deposit = Deposit::firstOrFail();
        $this->actingAs($manager)->post("/admin/deposits/{$deposit->id}/review", ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($manager)->post("/admin/deposits/{$deposit->id}/review", ['decision' => 'approve'])->assertSessionHasErrors('status');

        $this->assertSame('250.000000000000000000', $this->fundingWallet($user)->balance);
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_withdrawal_locks_funds_and_is_debited_on_approval(): void
    {
        $user = $this->trader();
        $this->actingAs($user)->postJson('/api/v1/wallet/deposits', ['currency' => 'USDT', 'amount' => '100'], $this->idempotencyHeaders());
        $admin = $this->staff(Role::ADMIN);
        $this->actingAs($admin)->post('/admin/deposits/'.Deposit::first()->id.'/review', ['decision' => 'approve']);

        $this->actingAs($user)->postJson('/api/v1/wallet/withdrawals', ['currency' => 'USDT', 'amount' => '150', 'destination' => 'TXabc'], $this->idempotencyHeaders())
            ->assertJsonValidationErrors('amount');
        $this->actingAs($user)->postJson('/api/v1/wallet/withdrawals', ['currency' => 'USDT', 'amount' => '60', 'destination' => 'TXabc'], $this->idempotencyHeaders())->assertCreated();
        $this->assertSame('60.000000000000000000', $this->fundingWallet($user)->locked_balance);

        $this->actingAs($admin)->post('/admin/withdrawals/'.Withdrawal::first()->id.'/review', ['decision' => 'approve'])->assertRedirect();

        $wallet = $this->fundingWallet($user);
        $this->assertSame('40.000000000000000000', $wallet->balance);
        $this->assertSame('0.000000000000000000', $wallet->locked_balance);
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_rejected_withdrawal_unlocks_funds(): void
    {
        $user = $this->trader();
        $admin = $this->staff(Role::ADMIN);
        $this->actingAs($user)->postJson('/api/v1/wallet/deposits', ['currency' => 'USDT', 'amount' => '100'], $this->idempotencyHeaders());
        $this->actingAs($admin)->post('/admin/deposits/'.Deposit::first()->id.'/review', ['decision' => 'approve']);
        $this->actingAs($user)->postJson('/api/v1/wallet/withdrawals', ['currency' => 'USDT', 'amount' => '60', 'destination' => 'TXabc'], $this->idempotencyHeaders());

        $this->actingAs($admin)->post('/admin/withdrawals/'.Withdrawal::first()->id.'/review', ['decision' => 'reject', 'note' => 'Address mismatch']);

        $this->assertSame('100.000000000000000000', $this->fundingWallet($user)->balance);
        $this->assertSame('0.000000000000000000', $this->fundingWallet($user)->locked_balance);
    }

    public function test_staff_cannot_approve_their_own_withdrawal(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $other = $this->staff(Role::ADMIN);
        $this->actingAs($admin)->postJson('/api/v1/wallet/deposits', ['currency' => 'USDT', 'amount' => '100'], $this->idempotencyHeaders());
        $this->actingAs($other)->post('/admin/deposits/'.Deposit::first()->id.'/review', ['decision' => 'approve']);
        $this->actingAs($admin)->postJson('/api/v1/wallet/withdrawals', ['currency' => 'USDT', 'amount' => '10', 'destination' => 'TXabc'], $this->idempotencyHeaders());

        $this->actingAs($admin)->post('/admin/withdrawals/'.Withdrawal::first()->id.'/review', ['decision' => 'approve'])->assertForbidden();
    }

    public function test_support_staff_cannot_review_withdrawals(): void
    {
        $user = $this->trader();
        $this->actingAs($user)->postJson('/api/v1/wallet/deposits', ['currency' => 'USDT', 'amount' => '100'], $this->idempotencyHeaders());

        $this->actingAs($this->staff(Role::SUPPORT))->post('/admin/deposits/'.Deposit::first()->id.'/review', ['decision' => 'approve'])->assertForbidden();
    }
}
