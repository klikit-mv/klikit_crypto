<?php

namespace Tests\Feature\Mining;

use App\Models\MiningDevice;
use App\Models\MiningEarning;
use App\Models\MiningPool;
use App\Models\MiningPoolAccount;
use App\Models\MiningProfitability;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MiningTest extends TestCase
{
    use RefreshDatabase;

    private MiningPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->pool = MiningPool::create(['name' => 'TestPool', 'endpoint' => 'stratum+tcp://x', 'algorithm' => 'SHA-256', 'coin' => 'BTC', 'fee_percent' => 2, 'api_type' => 'generic_json', 'api_url' => 'https://pool.test']);
        $this->travelTo(now()->setTime(18, 0));
    }

    private function enterprise()
    {
        $user = $this->trader();
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', 'enterprise')->first()));

        return $user;
    }

    private function addDevice($user, array $overrides = [])
    {
        return $this->actingAs($user)->postJson('/api/v1/mining/devices', $overrides + [
            'name' => 'S19', 'device_type' => 'asic', 'pool_id' => $this->pool->id, 'power_watts' => 3250, 'electricity_rate' => 0.1,
        ])->assertCreated();
    }

    public function test_mining_requires_enterprise_entitlement(): void
    {
        $user = $this->trader();
        $this->actingAs($user)->getJson('/api/v1/mining/devices')->assertStatus(402);
        $this->actingAs($user)->get('/mining')->assertRedirect('/subscription');
    }

    public function test_agent_reports_stats_with_device_token_only(): void
    {
        $user = $this->enterprise();
        $token = $this->addDevice($user)->json('agent_token');
        $this->assertStringStartsWith('mdt_', $token);
        $this->assertNotSame($token, MiningDevice::first()->agent_token_hash);

        $payload = ['workers' => [['name' => 'rig-asc0', 'hashrate' => 95e12, 'temperature' => 71, 'accepted' => 10, 'rejected' => 0]]];
        $this->postJson('/api/v1/mining/agent/stats', $payload, ['Authorization' => 'Bearer mdt_wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/mining/agent/stats', $payload, ['Authorization' => "Bearer $token"])->assertOk()->assertJsonPath('stored', 1);

        $this->assertSame('online', MiningDevice::first()->status);
        $this->assertEquals(95e12, $this->actingAs($user)->getJson('/api/v1/mining/devices')->json('devices.0.hashrate'));

        $this->travel(11)->minutes();
        $this->artisan('mining:profitability');
        $this->assertSame('offline', MiningDevice::first()->status);
    }

    public function test_profitability_is_revenue_minus_electricity_pool_fees_and_other_costs(): void
    {
        $user = $this->enterprise();
        $btc = $this->symbol('BTC/USDT');
        $this->setPrice($btc, '60000', '60000');
        $token = $this->addDevice($user)->json('agent_token');
        $device = MiningDevice::first();

        // Reports during 10 distinct hours today -> 10h uptime * 3.25 kW * $0.10 = $3.25
        foreach (range(8, 17) as $hour) {
            $this->travelTo(now()->setTime($hour, 5));
            $this->postJson('/api/v1/mining/agent/stats', ['workers' => [['name' => 'w1', 'hashrate' => 100e12]]], ['Authorization' => "Bearer $token"])->assertOk();
        }
        $this->travelTo(now()->setTime(18, 0));
        $this->setPrice($btc, '60000', '60000');

        $this->actingAs($user)->postJson('/api/v1/mining/earnings', ['pool_id' => $this->pool->id, 'amount' => '0.0002', 'currency' => 'BTC', 'date' => now()->toDateString()])->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/mining/devices/{$device->id}/expenses", ['amount' => '1.5', 'date' => now()->toDateString()])->assertCreated();
        $this->artisan('mining:profitability', ['--date' => now()->toDateString()]);

        $p = MiningProfitability::sole();
        // revenue 0.0002 BTC * 60000 = 12.00; pool fee 2% = 0.24; electricity 3.25; other 1.50
        $this->assertSame(['12.00000000', '4.99000000', '7.01000000'], [$p->revenue, $p->expenses, $p->net_profit]);

        $this->actingAs($user)->get('/mining')->assertOk()->assertSee('S19')->assertSee('$7.01');
        $this->actingAs($user)->get("/mining/devices/{$device->id}")->assertOk();
    }

    public function test_revenue_is_split_between_devices_by_hashrate(): void
    {
        $user = $this->enterprise();
        $this->setPrice($this->symbol('BTC/USDT'), '50000', '50000');
        $t1 = $this->addDevice($user, ['name' => 'A', 'power_watts' => 0])->json('agent_token');
        $t2 = $this->addDevice($user, ['name' => 'B', 'power_watts' => 0])->json('agent_token');
        $this->postJson('/api/v1/mining/agent/stats', ['workers' => [['name' => 'a', 'hashrate' => 300]]], ['Authorization' => "Bearer $t1"]);
        $this->postJson('/api/v1/mining/agent/stats', ['workers' => [['name' => 'b', 'hashrate' => 100]]], ['Authorization' => "Bearer $t2"]);
        $this->actingAs($user)->postJson('/api/v1/mining/earnings', ['pool_id' => $this->pool->id, 'amount' => '0.001', 'currency' => 'BTC', 'date' => now()->toDateString()]);

        $this->artisan('mining:profitability', ['--date' => now()->toDateString()]);

        $this->assertSame(['37.50000000', '12.50000000'], MiningProfitability::orderBy('device_id')->pluck('revenue')->all());
    }

    public function test_pool_api_sync_imports_earnings_and_payouts_idempotently(): void
    {
        $user = $this->enterprise();
        Http::fake(['pool.test/*' => Http::response([
            'earnings' => [['date' => now()->toDateString(), 'amount' => '0.0003', 'currency' => 'btc']],
            'payouts' => [['amount' => '0.01', 'currency' => 'BTC', 'tx_hash' => 'abc123', 'paid_at' => now()->toIso8601String()]],
        ])]);
        $this->actingAs($user)->postJson('/api/v1/mining/pool-accounts', ['pool_id' => $this->pool->id, 'account_name' => 'acct1', 'api_key' => 'secret-key'])->assertCreated();

        $this->artisan('mining:sync-pools')->assertSuccessful();
        $this->artisan('mining:sync-pools')->assertSuccessful();

        $this->assertSame(1, MiningEarning::count());
        $this->assertSame('BTC', MiningEarning::first()->currency);
        $this->assertDatabaseCount('mining_payouts', 1);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret-key') && str_contains($r->url(), '/accounts/acct1/earnings'));
        $this->assertNotSame('secret-key', \DB::table('mining_pool_accounts')->value('encrypted_api_key'));
        $this->assertNotNull(MiningPoolAccount::first()->last_synced_at);
    }

    public function test_admin_manages_pools(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $this->actingAs($admin)->post('/admin/mining/pools', ['name' => 'P2', 'endpoint' => 'stratum+tcp://p2', 'algorithm' => 'Scrypt', 'coin' => 'LTC', 'fee_percent' => 1, 'api_type' => 'manual'])->assertRedirect();
        $this->actingAs($admin)->get('/admin/mining')->assertOk()->assertSee('P2');
        $this->actingAs($this->staff(Role::SUPPORT))->get('/admin/mining')->assertForbidden();
    }
}
