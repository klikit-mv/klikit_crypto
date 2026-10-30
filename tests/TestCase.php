<?php

namespace Tests;

use App\Models\MarketPrice;
use App\Models\TradingSymbol;
use App\Models\User;
use App\Services\Access\UserRegistrationService;
use App\Support\Decimal;
use Database\Seeders\MarketSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // tests must never reach real exchanges, pools or Telegram
    }

    protected function seedPlatform(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, SubscriptionPlanSeeder::class, MarketSeeder::class]);
    }

    /** A registered user with funded paper wallets (10,000 USDT / USD). */
    protected function trader(array $attributes = []): User
    {
        return app(UserRegistrationService::class)->register($attributes + [
            'name' => 'Trader',
            'email' => Str::random(8).'@example.com',
            'password' => 'secret-password-1',
        ]);
    }

    protected function staff(string $role): User
    {
        $user = $this->trader();
        $user->assignRole($role);

        return $user;
    }

    protected function symbol(string $symbol, string $market = 'SPOT'): TradingSymbol
    {
        return TradingSymbol::where('symbol', $symbol)->where('market_type', $market)->firstOrFail();
    }

    protected function setPrice(TradingSymbol $symbol, string $bid, string $ask): void
    {
        MarketPrice::create([
            'symbol_id' => $symbol->id,
            'source' => 'test',
            'bid' => $bid,
            'ask' => $ask,
            'price' => (string) Decimal::div(Decimal::of($bid)->plus(Decimal::of($ask)), 2),
            'timestamp' => now()->addSecond(),
        ]);
        $this->travel(2)->seconds();
    }

    protected function idempotencyHeaders(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Accept' => 'application/json'];
    }
}
