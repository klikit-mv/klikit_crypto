<?php

namespace Tests\Feature\Analysis;

use App\Models\MarketCandle;
use App\Models\PriceAlert;
use App\Models\SubscriptionPlan;
use App\Services\Analysis\CandleService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\Data\Ticker;
use App\Services\Trading\MarketData\MarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChartsAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function subscribe($user, string $plan): void
    {
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', $plan)->first()));
    }

    public function test_price_ticks_build_ohlc_candles(): void
    {
        $symbol = $this->symbol('ETH/USDT');
        $candles = app(CandleService::class);
        $base = Carbon::parse('2026-01-05 10:00:00', 'UTC');

        foreach ([['3500', 0], ['3550', 10], ['3480', 20], ['3510', 50]] as [$price, $sec]) {
            $candles->recordTick($symbol->id, $price, $base->copy()->addSeconds($sec));
        }

        $minute = MarketCandle::where(['symbol_id' => $symbol->id, 'interval' => '1m'])->first();
        $this->assertSame(['3500', '3550', '3480', '3510'], array_map(fn ($v) => rtrim(rtrim($v, '0'), '.'), [$minute->open, $minute->high, $minute->low, $minute->close]));
        $this->assertSame(6, MarketCandle::where('symbol_id', $symbol->id)->count()); // one per interval
    }

    public function test_stored_market_prices_feed_candles(): void
    {
        $symbol = $this->symbol('EUR/USD', 'FOREX');
        app(MarketDataService::class)->store($symbol->id, new Ticker('EUR/USD', '1.1', '1.1002', '1.1001'), 'test');

        $this->assertDatabaseHas('market_candles', ['symbol_id' => $symbol->id, 'interval' => '1h']);
    }

    public function test_binance_backfill(): void
    {
        Http::fake(['*/api/v3/klines*' => Http::response([
            [1767600000000, '3500', '3600', '3400', '3550', '100'],
            [1767603600000, '3550', '3700', '3500', '3650', '120'],
        ])]);

        $this->artisan('market:candles', ['--interval' => '1h'])->assertSuccessful();
        $this->assertSame(2, MarketCandle::where('symbol_id', $this->symbol('ETH/USDT')->id)->where('interval', '1h')->count());
    }

    public function test_chart_data_locks_advanced_indicators_for_free_users(): void
    {
        $user = $this->trader();
        $symbol = $this->symbol('ETH/USDT');
        $start = Carbon::parse('2026-01-01', 'UTC');
        foreach (range(0, 59) as $i) {
            app(CandleService::class)->recordTick($symbol->id, (string) (3000 + $i * 5), $start->copy()->addHours($i));
        }

        $this->actingAs($user)->getJson("/api/v1/charts/{$symbol->id}?interval=1h&indicators=ema:20,rsi,macd")
            ->assertOk()
            ->assertJsonCount(60, 'candles')
            ->assertJsonPath('locked_indicators', ['rsi', 'macd'])
            ->assertJsonCount(41, 'indicators.ema:20');

        $this->subscribe($user, 'pro');
        $this->actingAs($user)->getJson("/api/v1/charts/{$symbol->id}?interval=1h&indicators=rsi,macd")
            ->assertOk()->assertJsonPath('locked_indicators', [])->assertJsonPath('indicators.rsi.45.value', 100);

        $this->actingAs($user)->get('/charts')->assertOk()->assertSee('lightweight-charts');
    }

    public function test_price_alert_triggers_once_and_notifies(): void
    {
        $user = $this->trader();
        $symbol = $this->symbol('BTC/USDT');

        $this->actingAs($user)->postJson('/api/v1/alerts', ['symbol_id' => $symbol->id, 'condition' => 'price_above', 'target' => '70000'])->assertCreated();
        $this->artisan('alerts:check')->expectsOutput('0 alerts triggered.');

        $this->setPrice($symbol, '70100', '70110');
        $this->artisan('alerts:check')->expectsOutput('1 alerts triggered.');
        $this->artisan('alerts:check')->expectsOutput('0 alerts triggered.');

        $this->assertSame('triggered', PriceAlert::first()->status);
        $this->assertStringContainsString('BTC/USDT price above 70000', $user->notifications()->first()->data['message']);
    }

    public function test_alert_limits_and_rsi_gating(): void
    {
        $user = $this->trader();
        $symbol = $this->symbol('BTC/USDT');

        foreach (range(1, 3) as $n) {
            $this->actingAs($user)->postJson('/api/v1/alerts', ['symbol_id' => $symbol->id, 'condition' => 'price_below', 'target' => 1000 * $n])->assertCreated();
        }
        $this->actingAs($user)->postJson('/api/v1/alerts', ['symbol_id' => $symbol->id, 'condition' => 'price_below', 'target' => 5])->assertJsonValidationErrors('symbol_id');

        PriceAlert::query()->update(['status' => 'cancelled']);
        $this->actingAs($user)->postJson('/api/v1/alerts', ['symbol_id' => $symbol->id, 'condition' => 'rsi_above', 'target' => 70])->assertJsonValidationErrors('condition');
    }
}
