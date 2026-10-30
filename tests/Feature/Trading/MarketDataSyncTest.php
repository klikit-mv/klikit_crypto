<?php

namespace Tests\Feature\Trading;

use App\Models\MarketPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketDataSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_sync_stores_public_spot_prices(): void
    {
        $this->seedPlatform();
        Http::fake(['*/api/v3/ticker/24hr*' => Http::response([
            ['symbol' => 'BTCUSDT', 'bidPrice' => '70000.10', 'askPrice' => '70000.20', 'lastPrice' => '70000.15', 'priceChangePercent' => '2.5', 'volume' => '1234.5'],
            ['symbol' => 'UNKNOWN', 'bidPrice' => '1', 'askPrice' => '1', 'lastPrice' => '1', 'priceChangePercent' => '0', 'volume' => '0'],
        ])]);

        $this->artisan('market:sync')->assertSuccessful()->expectsOutput('Stored 1 prices.');

        $btc = $this->symbol('BTC/USDT');
        $this->assertSame('70000.150000000000000000', MarketPrice::where('symbol_id', $btc->id)->latest('id')->value('price'));
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '"BTCUSDT"') && ! str_contains(urldecode($request->url()), 'EURUSD'));
    }
}
