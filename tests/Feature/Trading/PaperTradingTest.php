<?php

namespace Tests\Feature\Trading;

use App\Models\Order;
use App\Models\Position;
use App\Models\Wallet;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaperTradingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function wallet($user, string $currency): Wallet
    {
        return Wallet::where(['user_id' => $user->id, 'currency' => $currency, 'wallet_type' => 'paper'])->firstOrFail();
    }

    public function test_market_buy_fills_at_ask_and_settles_through_the_ledger(): void
    {
        $user = $this->trader();
        $btc = $this->symbol('BTC/USDT');
        $this->setPrice($btc, '60000', '60010');

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $btc->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.01',
        ], $this->idempotencyHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', 'filled')
            ->assertJsonPath('data.average_fill_price', '60010');

        // 0.01 * 60010 = 600.10 + 0.1% fee 0.6001
        $this->assertSame('9399.299900000000000000', $this->wallet($user, 'USDT')->balance);
        $this->assertSame('0.000000000000000000', $this->wallet($user, 'USDT')->locked_balance);
        $this->assertSame('0.010000000000000000', $this->wallet($user, 'BTC')->balance);
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_market_sell_credits_proceeds_minus_fee(): void
    {
        $user = $this->trader();
        $btc = $this->symbol('BTC/USDT');
        $this->setPrice($btc, '60000', '60000');
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', ['symbol_id' => $btc->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.01'], $this->idempotencyHeaders());
        $this->setPrice($btc, '61000', '61000');

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', ['symbol_id' => $btc->id, 'side' => 'sell', 'type' => 'market', 'quantity' => '0.01'], $this->idempotencyHeaders())
            ->assertCreated()->assertJsonPath('data.status', 'filled');

        // 10000 - 600 - 0.6 + 610 - 0.61
        $this->assertSame('10008.790000000000000000', $this->wallet($user, 'USDT')->balance);
        $this->assertSame('0.000000000000000000', $this->wallet($user, 'BTC')->balance);
    }

    public function test_same_idempotency_key_never_creates_a_second_order(): void
    {
        $user = $this->trader();
        $payload = ['symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1'];
        $headers = $this->idempotencyHeaders('order-key-0001');

        $first = $this->actingAs($user)->postJson('/api/v1/crypto/orders', $payload, $headers)->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $payload, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', ['quantity' => '0.2'] + $payload, $headers)->assertUnprocessable();

        $this->assertSame(1, Order::count());
    }

    public function test_orders_require_an_idempotency_key(): void
    {
        $this->actingAs($this->trader())->postJson('/api/v1/crypto/orders', ['symbol_id' => $this->symbol('BTC/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.01'])
            ->assertStatus(400);
    }

    public function test_limit_order_rests_until_marketable_then_fills_on_sync(): void
    {
        $user = $this->trader();
        $eth = $this->symbol('ETH/USDT');
        $this->setPrice($eth, '3500', '3501');

        $id = $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $eth->id, 'side' => 'buy', 'type' => 'limit', 'quantity' => '0.25', 'price' => '3400',
        ], $this->idempotencyHeaders())->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

        // 0.25 * 3400 * 1.001 reserved
        $this->assertSame('850.850000000000000000', $this->wallet($user, 'USDT')->locked_balance);

        $this->setPrice($eth, '3390', '3395');
        Artisan::call('trading:sync-orders');

        $order = Order::where('uuid', $id)->first();
        $this->assertSame(Order::FILLED, $order->status);
        $this->assertSame('3395.000000000000000000', $order->average_fill_price);
        $this->assertSame('0.000000000000000000', $this->wallet($user, 'USDT')->locked_balance);
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_cancelling_an_open_order_releases_reserved_funds(): void
    {
        $user = $this->trader();
        $eth = $this->symbol('ETH/USDT');

        $id = $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $eth->id, 'side' => 'buy', 'type' => 'limit', 'quantity' => '0.1', 'price' => '1000',
        ], $this->idempotencyHeaders())->json('data.id');

        $this->actingAs($user)->postJson("/api/v1/crypto/orders/$id/cancel", [], $this->idempotencyHeaders())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('0.000000000000000000', $this->wallet($user, 'USDT')->locked_balance);
        $this->assertSame('10000.000000000000000000', $this->wallet($user, 'USDT')->balance);
    }

    public function test_users_cannot_cancel_other_users_orders(): void
    {
        $owner = $this->trader();
        $id = $this->actingAs($owner)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'limit', 'quantity' => '0.1', 'price' => '1000',
        ], $this->idempotencyHeaders())->json('data.id');

        $this->actingAs($this->trader())->postJson("/api/v1/crypto/orders/$id/cancel", [], $this->idempotencyHeaders())->assertForbidden();
    }

    public function test_risk_engine_rejects_orders_above_max_amount_per_trade_and_records_them(): void
    {
        $user = $this->trader();

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('BTC/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '1',
        ], $this->idempotencyHeaders())->assertUnprocessable()->assertJsonPath('rule', 'max_amount_per_trade');

        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.rejected', 'user_id' => $user->id]);
        $this->assertSame('0.000000000000000000', $this->wallet($user, 'USDT')->locked_balance);
    }

    public function test_insufficient_balance_is_rejected(): void
    {
        $user = $this->trader();
        $user->riskProfile->update(['max_amount_per_trade' => 1000000]);

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '5',
        ], $this->idempotencyHeaders())->assertUnprocessable()->assertJsonPath('rule', 'insufficient_balance');
    }

    public function test_stop_loss_requirement_and_direction_are_enforced(): void
    {
        $user = $this->trader();
        $user->riskProfile->update(['require_stop_loss' => true]);
        $eth = $this->symbol('ETH/USDT');
        $this->setPrice($eth, '3500', '3500');
        $order = ['symbol_id' => $eth->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1'];

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order, $this->idempotencyHeaders())->assertJsonPath('rule', 'stop_loss_required');
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order + ['stop_loss' => '3600'], $this->idempotencyHeaders())->assertJsonPath('rule', 'stop_loss_direction');
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order + ['stop_loss' => '3300'], $this->idempotencyHeaders())->assertCreated();
    }

    public function test_spot_orders_cannot_use_leverage(): void
    {
        $this->actingAs($this->trader())->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1', 'leverage' => '5',
        ], $this->idempotencyHeaders())->assertJsonPath('rule', 'leverage');
    }

    public function test_platform_halt_and_user_emergency_stop_block_trading(): void
    {
        $user = $this->trader();
        $order = ['symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1'];

        app(PlatformSettings::class)->set('trading.halted', true);
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order, $this->idempotencyHeaders())->assertJsonPath('rule', 'platform_halted');
        app(PlatformSettings::class)->set('trading.halted', false);

        $this->actingAs($user)->postJson('/api/v1/trading/emergency-stop')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order, $this->idempotencyHeaders())->assertJsonPath('rule', 'user_trading_disabled');
    }

    public function test_live_mode_is_refused_while_live_trading_is_disabled(): void
    {
        $this->actingAs($this->trader())->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1', 'mode' => 'live',
        ], $this->idempotencyHeaders())->assertJsonPath('rule', 'live_disabled');
    }

    public function test_forex_market_routes_only_forex_instruments(): void
    {
        $this->actingAs($this->trader())->postJson('/api/v1/forex/orders', [
            'symbol_id' => $this->symbol('BTC/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.01',
        ], $this->idempotencyHeaders())->assertUnprocessable()->assertJsonValidationErrors('symbol_id');
    }

    public function test_forex_position_opens_with_margin_and_closes_with_realised_pnl(): void
    {
        $this->travelTo(now()->next('Wednesday')->setTime(12, 0));
        $user = $this->trader();
        $user->riskProfile->update(['max_leverage' => 10, 'max_amount_per_trade' => 5000]);
        $eur = $this->symbol('EUR/USD', 'FOREX');
        $this->setPrice($eur, '1.08000', '1.08010');

        // 0.02 lots = 2,000 EUR, notional 2,160.20 USD at 10x -> margin 216.02, fee 2.1602
        $this->actingAs($user)->postJson('/api/v1/forex/orders', [
            'symbol_id' => $eur->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.02', 'leverage' => '10',
        ], $this->idempotencyHeaders())->assertCreated()->assertJsonPath('data.status', 'filled');

        $position = Position::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('long', $position->side);
        $this->assertSame('216.020000000000000000', $position->margin);
        $this->assertSame('216.020000000000000000', $this->wallet($user, 'USD')->locked_balance);
        $this->assertSame('9997.839800000000000000', $this->wallet($user, 'USD')->balance);

        $this->setPrice($eur, '1.09010', '1.09020');
        $this->actingAs($user)->postJson("/api/v1/positions/{$position->id}/close", [], $this->idempotencyHeaders())->assertOk()->assertJsonPath('data.status', 'closed');

        // gross (1.09010 - 1.08010) * 2000 = 20.00, close fee 0.1% of 2180.20 = 2.1802
        $this->assertSame('17.819800000000000000', $position->fresh()->realized_pnl);
        $this->assertSame('10015.659600000000000000', $this->wallet($user, 'USD')->balance);
        $this->assertSame('0.000000000000000000', $this->wallet($user, 'USD')->locked_balance);
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_forex_orders_are_rejected_when_the_market_is_closed(): void
    {
        $this->travelTo(now()->next('Saturday')->setTime(12, 0));
        $user = $this->trader();
        $user->riskProfile->update(['max_leverage' => 10, 'max_amount_per_trade' => 5000]);
        $eur = $this->symbol('EUR/USD', 'FOREX');
        $this->setPrice($eur, '1.08', '1.08');

        $this->actingAs($user)->postJson('/api/v1/forex/orders', [
            'symbol_id' => $eur->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.01', 'leverage' => '10',
        ], $this->idempotencyHeaders())->assertCreated()->assertJsonPath('data.status', 'rejected');

        $this->assertSame('0.000000000000000000', $this->wallet($user, 'USD')->locked_balance);
    }

    public function test_daily_loss_limit_blocks_new_orders(): void
    {
        $this->travelTo(now()->next('Wednesday')->setTime(12, 0));
        $user = $this->trader();
        $user->riskProfile->update(['max_leverage' => 10, 'max_amount_per_trade' => 5000, 'max_daily_loss' => 10]);
        $eur = $this->symbol('EUR/USD', 'FOREX');
        $this->setPrice($eur, '1.08', '1.08');

        $this->actingAs($user)->postJson('/api/v1/forex/orders', ['symbol_id' => $eur->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.02', 'leverage' => '10'], $this->idempotencyHeaders());
        $this->setPrice($eur, '1.07', '1.07');
        $position = Position::firstOrFail();
        $this->actingAs($user)->postJson("/api/v1/positions/{$position->id}/close", [], $this->idempotencyHeaders())->assertOk();

        $this->actingAs($user)->postJson('/api/v1/forex/orders', ['symbol_id' => $eur->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.01', 'leverage' => '10'], $this->idempotencyHeaders())
            ->assertJsonPath('rule', 'max_daily_loss');
    }

    public function test_web_order_form_places_paper_order(): void
    {
        $user = $this->trader();
        $this->actingAs($user)->get('/trading')->assertOk();

        $this->actingAs($user)->post('/trading/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/trading');

        $this->assertSame(Order::FILLED, Order::firstOrFail()->status);
    }
}
