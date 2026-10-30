<?php

namespace Tests\Feature\Trading;

use App\Models\Exchange;
use App\Models\ExchangeAccount;
use App\Models\Order;
use App\Models\SubscriptionPlan;
use App\Models\Wallet;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        Exchange::where('code', 'binance')->update(['status' => 'enabled']);
        config(['trading.live_trading_enabled' => true, 'trading.bridges.ccxt.url' => 'http://bridge.test', 'trading.bridges.ccxt.token' => 'bridge-token']);
    }

    private function starter()
    {
        $user = $this->trader();
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', 'starter')->first()));

        return $user;
    }

    private function connect($user, string $scope = 'trade'): int
    {
        return $this->actingAs($user)->postJson('/api/v1/accounts/exchanges', [
            'exchange_id' => Exchange::where('code', 'binance')->value('id'), 'label' => 'Main', 'api_key' => 'KEY-1234', 'api_secret' => 'SECRET', 'permissions_scope' => $scope,
        ])->json('data.id');
    }

    public function test_keys_with_withdrawal_permission_are_refused(): void
    {
        $user = $this->starter();
        $id = $this->connect($user);
        Http::fake(['bridge.test/verify' => Http::response(['ok' => true, 'can_trade' => true, 'can_withdraw' => true])]);

        $this->actingAs($user)->postJson("/api/v1/accounts/exchanges/$id/verify")->assertOk()
            ->assertJsonPath('data.status', 'invalid')->assertJsonPath('data.status_reason', 'This API key has withdrawal permission. Create a key without withdrawals.');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer bridge-token') && $r['credentials']['api_secret'] === 'SECRET');
    }

    public function test_live_orders_need_a_verified_trade_account(): void
    {
        $user = $this->starter();
        $id = $this->connect($user, 'read_only');
        $order = ['symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1', 'mode' => 'live'];

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order, $this->idempotencyHeaders())->assertJsonPath('rule', 'account_required');
        $this->actingAs($user)->postJson('/api/v1/crypto/orders', $order + ['exchange_account_id' => $id], $this->idempotencyHeaders())->assertJsonPath('rule', 'account_not_tradable');
    }

    public function test_verified_live_order_routes_through_bridge_without_touching_the_ledger(): void
    {
        $user = $this->starter();
        $id = $this->connect($user);
        Http::fake([
            'bridge.test/verify' => Http::response(['ok' => true, 'can_trade' => true, 'can_withdraw' => false]),
            'bridge.test/orders' => Http::response(['status' => 'filled', 'external_order_id' => 'B-1', 'fills' => [['price' => '3501.5', 'quantity' => '0.1', 'fee' => '0.35', 'fee_currency' => 'USDT', 'trade_id' => 'T-1']]]),
        ]);
        $this->actingAs($user)->postJson("/api/v1/accounts/exchanges/$id/verify")->assertJsonPath('data.status', 'verified');

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1', 'mode' => 'live', 'exchange_account_id' => $id,
        ], $this->idempotencyHeaders())->assertCreated()->assertJsonPath('data.status', 'filled')->assertJsonPath('data.average_fill_price', '3501.5');

        $order = Order::sole();
        $this->assertSame('B-1', $order->external_order_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orders') && $r['client_order_id'] === $order->uuid);
        $this->assertSame('10000.000000000000000000', Wallet::where(['user_id' => $user->id, 'currency' => 'USDT'])->value('balance'));
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_bridge_outage_marks_order_failed_and_is_reported(): void
    {
        $user = $this->starter();
        $id = $this->connect($user);
        ExchangeAccount::whereKey($id)->update(['status' => 'verified']);
        Http::fake(['bridge.test/*' => Http::response(['message' => 'down'], 503)]);

        $this->actingAs($user)->postJson('/api/v1/crypto/orders', [
            'symbol_id' => $this->symbol('ETH/USDT')->id, 'side' => 'buy', 'type' => 'market', 'quantity' => '0.1', 'mode' => 'live', 'exchange_account_id' => $id,
        ], $this->idempotencyHeaders())->assertJsonPath('data.status', 'failed');

        $this->assertStringContainsString('HTTP 503', Order::sole()->reject_reason);
    }
}
