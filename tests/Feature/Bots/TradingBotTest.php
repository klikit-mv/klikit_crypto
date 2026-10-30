<?php

namespace Tests\Feature\Bots;

use App\Models\BotSignal;
use App\Models\BotStrategy;
use App\Models\Order;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\TradingBot;
use App\Services\Analysis\CandleService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\BotStrategySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TradingBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->seed(BotStrategySeeder::class);
    }

    private function subscriber(string $plan = 'pro')
    {
        $user = $this->trader();
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', $plan)->first()));

        return $user;
    }

    private function createBot($user, string $mode, array $params = [])
    {
        return $this->actingAs($user)->postJson('/api/v1/bots', [
            'name' => 'DCA ETH',
            'strategy_id' => BotStrategy::where('strategy_type', 'dca')->value('id'),
            'symbol_id' => $this->symbol('ETH/USDT')->id,
            'execution_mode' => $mode,
            'parameters' => $params + ['order_amount' => 350, 'take_profit_pct' => 5],
        ]);
    }

    public function test_free_users_cannot_create_bots(): void
    {
        $this->createBot($this->trader(), 'semi_automatic')->assertJsonValidationErrors('strategy_id');
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        $this->createBot($this->subscriber(), 'semi_automatic', ['take_profit_pct' => -5])->assertJsonValidationErrors('take_profit_pct');
    }

    public function test_bots_only_trade_spot(): void
    {
        $this->actingAs($this->subscriber())->postJson('/api/v1/bots', [
            'name' => 'x', 'strategy_id' => BotStrategy::where('strategy_type', 'dca')->value('id'),
            'symbol_id' => $this->symbol('EUR/USD', 'FOREX')->id, 'execution_mode' => 'semi_automatic',
        ])->assertJsonValidationErrors('symbol_id');
    }

    public function test_fully_automatic_dca_bot_buys_then_takes_profit(): void
    {
        $user = $this->subscriber();
        $eth = $this->symbol('ETH/USDT');
        $this->setPrice($eth, '3500', '3500');
        $id = $this->createBot($user, 'fully_automatic')->assertCreated()->json('data.id');

        $this->actingAs($user)->postJson("/api/v1/bots/$id/start")->assertOk();
        $this->artisan('bots:run')->expectsOutput('1 bots evaluated.');

        $buy = Order::sole();
        $this->assertSame(['bot', 'buy', Order::FILLED, '0.1'], [$buy->source, $buy->side, $buy->status, rtrim(rtrim($buy->filled_quantity, '0'), '.')]);

        $this->setPrice($eth, '3700', '3701');
        $this->artisan('bots:run');

        $sell = Order::where('side', 'sell')->sole();
        $this->assertSame(Order::FILLED, $sell->status);
        $bot = $this->actingAs($user)->getJson("/api/v1/bots/$id")->assertOk();
        $bot->assertJsonPath('data.holding', '0')->assertJsonPath('data.closed_trades', 1);
        // (3700 - 3500) * 0.1 - fees 0.35 - 0.37
        $this->assertSame('19.28', $bot->json('data.realized_pnl'));

        $this->artisan('bots:performance');
        $this->assertDatabaseHas('bot_performance', ['bot_id' => $id, 'trades_count' => 1]);
    }

    public function test_semi_automatic_bot_waits_for_approval(): void
    {
        $user = $this->subscriber();
        $this->setPrice($this->symbol('ETH/USDT'), '3500', '3500');
        $id = $this->createBot($user, 'semi_automatic')->json('data.id');
        $this->actingAs($user)->post("/bots/$id/start");

        $this->artisan('bots:run');
        $this->assertSame(0, Order::count());
        $signal = BotSignal::sole();
        $this->assertSame('pending_approval', $signal->status);

        $this->actingAs($user)->postJson("/api/v1/bots/signals/{$signal->id}/approve", [], $this->idempotencyHeaders())->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame(Order::FILLED, Order::sole()->status);
        $this->actingAs($user)->get('/bots')->assertOk();
        $this->actingAs($user)->get("/bots/$id")->assertOk()->assertSee('Scheduled DCA buy');
    }

    public function test_bot_respects_risk_engine(): void
    {
        $user = $this->subscriber();
        $this->setPrice($this->symbol('ETH/USDT'), '3500', '3500');
        $id = $this->createBot($user, 'fully_automatic', ['order_amount' => 5000])->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/bots/$id/start");

        $this->artisan('bots:run');

        $this->assertSame('failed', BotSignal::sole()->status);
        $this->assertStringContainsString('maximum amount per trade', BotSignal::sole()->reason);
    }

    public function test_starter_plan_cannot_run_bots_and_admin_can_stop_running_bot(): void
    {
        $this->createBot($this->subscriber('starter'), 'semi_automatic')->assertJsonValidationErrors('strategy_id');

        $user = $this->subscriber();
        $id = $this->createBot($user, 'semi_automatic')->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/bots/$id/start");

        $admin = $this->staff(Role::ADMIN);
        $this->actingAs($admin)->get('/admin/bots')->assertOk()->assertSee('DCA ETH');
        $this->actingAs($admin)->post("/admin/bots/$id/stop")->assertRedirect();
        $this->assertSame('stopped', TradingBot::find($id)->status);

        $this->actingAs($this->trader())->postJson("/api/v1/bots/$id/start")->assertNotFound();
    }

    public function test_backtest_runs_on_stored_candles(): void
    {
        $user = $this->subscriber();
        $eth = $this->symbol('ETH/USDT');
        $start = Carbon::parse('2026-01-01', 'UTC');
        $candles = app(CandleService::class);
        foreach (range(0, 239) as $h) {
            $candles->recordTick($eth->id, (string) round(3000 + 300 * sin($h / 12), 2), $start->copy()->addHours($h));
        }

        $response = $this->actingAs($user)->postJson('/api/v1/bots/backtests', [
            'strategy_id' => BotStrategy::where('strategy_type', 'rsi')->value('id'),
            'symbol_id' => $eth->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-11',
            'parameters' => ['order_amount' => 1000],
        ])->assertCreated();

        $results = $response->json('data.results');
        $this->assertSame(240, $results['candles']);
        $this->assertGreaterThan(0, $results['trades']);
        $this->assertArrayHasKey('max_drawdown_pct', $results);
        $this->actingAs($user)->get('/bots/backtests')->assertOk()->assertSee('Buy &amp; hold', false);

        $this->actingAs($user)->postJson('/api/v1/bots/backtests', [
            'strategy_id' => BotStrategy::where('strategy_type', 'rsi')->value('id'), 'symbol_id' => $eth->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-01-05',
        ])->assertJsonValidationErrors('period_start');
    }
}
