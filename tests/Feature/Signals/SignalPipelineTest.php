<?php

namespace Tests\Feature\Signals;

use App\Models\Order;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\SignalExecution;
use App\Models\SignalLog;
use App\Models\SubscriptionPlan;
use App\Models\TelegramBot;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Services\Signals\TelegramBotApi;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalPipelineTest extends TestCase
{
    use RefreshDatabase;

    private TelegramChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->channel = TelegramChannel::create(['channel_id' => '-1001', 'channel_name' => 'Alpha Calls', 'webhook_secret' => 'ingest-secret-123', 'signal_ttl_minutes' => 30]);
    }

    private function proTrader()
    {
        $user = $this->trader();
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', 'pro')->first()));

        return $user;
    }

    private function ingest(string $text, string $messageId = '1')
    {
        return $this->postJson("/api/v1/signals/ingest/{$this->channel->id}", ['text' => $text, 'message_id' => $messageId], ['X-Signal-Secret' => 'ingest-secret-123']);
    }

    public function test_ingest_requires_channel_secret(): void
    {
        $this->postJson("/api/v1/signals/ingest/{$this->channel->id}", ['text' => 'x'], ['X-Signal-Secret' => 'wrong'])->assertForbidden();
    }

    public function test_ambiguous_messages_are_logged_not_traded(): void
    {
        $this->ingest('BUY BTC/USDT maybe soon')->assertOk()->assertJsonPath('accepted', false);

        $this->assertSame(0, TelegramSignal::count());
        $this->assertSame('ambiguous', SignalLog::first()->result);
    }

    public function test_manual_approval_flow_places_order_through_risk_engine(): void
    {
        $user = $this->proTrader();
        $eth = $this->symbol('ETH/USDT');
        $this->setPrice($eth, '3500', '3501');

        $this->actingAs($user)->post("/signals/channels/{$this->channel->id}/subscribe", ['execution_mode' => 'manual_approval', 'amount_per_signal' => 350])->assertRedirect();
        $this->ingest("BUY ETH/USDT spot\nEntry 3450-3550\nTP 3700\nSL 3300")->assertJsonPath('accepted', true);

        $execution = SignalExecution::firstOrFail();
        $this->assertSame('pending_approval', $execution->status);
        $this->assertCount(1, $user->notifications);

        $this->actingAs($user)->postJson("/api/v1/signals/executions/{$execution->id}/approve", [], $this->idempotencyHeaders())->assertOk()->assertJsonPath('data.status', 'executed');

        $order = Order::firstOrFail();
        $this->assertSame('signal', $order->source);
        $this->assertSame('market', $order->type);
        $this->assertSame('0.099971430000000000', $order->quantity); // 350 USD / 3501 ask, rounded down to 8 dp
        $this->assertSame('3300.000000000000000000', $order->stop_loss);
        $this->assertSame('3700.000000000000000000', $order->take_profit);
        $this->assertSame(Order::FILLED, $order->status);
    }

    public function test_out_of_zone_entry_becomes_limit_order_and_duplicates_are_ignored(): void
    {
        $user = $this->proTrader();
        $this->setPrice($this->symbol('ETH/USDT'), '3600', '3601');
        $this->actingAs($user)->post("/signals/channels/{$this->channel->id}/subscribe", ['execution_mode' => 'auto', 'amount_per_signal' => 340]);

        $this->ingest("BUY ETH/USDT spot\nEntry 3400\nTP 3700\nSL 3300", '77');
        $this->ingest("BUY ETH/USDT spot\nEntry 3400\nTP 3700\nSL 3300", '77');

        $this->assertSame(1, TelegramSignal::count());
        $order = Order::sole();
        $this->assertSame(['limit', '3400.000000000000000000', Order::OPEN], [$order->type, $order->price, $order->status]);
    }

    public function test_risk_violation_marks_execution_failed(): void
    {
        $user = $this->proTrader();
        $this->actingAs($user)->post("/signals/channels/{$this->channel->id}/subscribe", ['execution_mode' => 'auto', 'amount_per_signal' => 5000]);

        $this->ingest("BUY ETH/USDT spot\nEntry: market\nTP 9000\nSL 100");

        $execution = SignalExecution::sole();
        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('maximum amount per trade', $execution->reason);
    }

    public function test_free_users_cannot_subscribe_and_daily_limit_applies(): void
    {
        $this->actingAs($this->trader())->postJson("/api/v1/signals/channels/{$this->channel->id}/subscribe", ['execution_mode' => 'manual_approval', 'amount_per_signal' => 100])->assertStatus(402);

        $user = $this->proTrader();
        PlanFeature::where('feature_key', 'signals.daily_limit')->update(['feature_value' => '1']);
        $this->actingAs($user)->postJson("/api/v1/signals/channels/{$this->channel->id}/subscribe", ['execution_mode' => 'manual_approval', 'amount_per_signal' => 100])->assertCreated();

        $this->ingest('BUY ETH/USDT spot entry 3400 TP 3700 SL 3300', '1');
        $this->ingest('BUY SOL/USDT spot entry 150 TP 170 SL 140', '2');

        $this->assertSame(['pending_approval', 'skipped'], SignalExecution::orderBy('id')->pluck('status')->all());
    }

    public function test_expired_signals_cannot_be_approved(): void
    {
        $user = $this->proTrader();
        $this->actingAs($user)->post("/signals/channels/{$this->channel->id}/subscribe", ['execution_mode' => 'manual_approval', 'amount_per_signal' => 100]);
        $this->ingest('BUY ETH/USDT spot entry 3400 TP 3700 SL 3300');

        $this->travel(31)->minutes();
        $this->artisan('signals:expire')->expectsOutput('1 signal approvals expired.');
        $this->actingAs($user)->postJson('/api/v1/signals/executions/'.SignalExecution::first()->id.'/approve', [], $this->idempotencyHeaders())
            ->assertJsonPath('rule', 'signal_not_pending');
        $this->actingAs($user)->get('/signals')->assertOk();
    }

    public function test_telegram_webhook_verifies_secret_and_ingests_channel_posts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'alpha_bot']])]);
        $admin = $this->staff(Role::ADMIN);
        $this->actingAs($admin)->post('/admin/telegram/bots', ['bot_token' => '123456:'.str_repeat('A', 35)])->assertSessionHas('status');
        $bot = TelegramBot::sole();
        $this->assertSame('active', $bot->status);
        $this->channel->update(['telegram_bot_id' => $bot->id]);

        $update = ['update_id' => 1, 'channel_post' => ['message_id' => 9, 'chat' => ['id' => -1001], 'text' => 'SELL EURUSD @ 1.0850 TP 1.0800 SL 1.0900']];
        $this->postJson("/telegram/webhook/{$bot->id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->assertForbidden();
        $this->postJson("/telegram/webhook/{$bot->id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => TelegramBotApi::webhookSecret($bot)])->assertOk();

        $this->assertSame('EUR/USD', TelegramSignal::sole()->symbol);
        $this->actingAs($admin)->get('/admin/telegram')->assertOk()->assertSee('Alpha Calls');
        $this->assertStringNotContainsString(str_repeat('A', 35), (string) \DB::table('telegram_bots')->value('bot_token_encrypted'));
    }
}
