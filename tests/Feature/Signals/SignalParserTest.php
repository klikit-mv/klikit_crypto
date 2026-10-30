<?php

namespace Tests\Feature\Signals;

use App\Services\Signals\AmbiguousSignal;
use App\Services\Signals\SignalParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignalParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public static function validMessages(): array
    {
        return [
            'labelled futures' => ["🚀 BUY BTC/USDT\nEntry: 67000 - 67500\nTP1: 68000\nTP2: 69000\nSL: 66000\nLeverage: 10x",
                ['symbol' => 'BTC/USDT', 'market_type' => 'FUTURES', 'side' => 'buy', 'entry' => '67000', 'entry_max' => '67500', 'stop_loss' => '66000', 'targets' => ['68000', '69000'], 'leverage' => '10']],
            'hashtag list' => ["#ETHUSDT LONG\nEntry 3500\nTargets 3600 3700 3800\nStop 3400",
                ['symbol' => 'ETH/USDT', 'market_type' => 'FUTURES', 'side' => 'buy', 'entry' => '3500', 'entry_max' => null, 'stop_loss' => '3400', 'targets' => ['3600', '3700', '3800'], 'leverage' => '1']],
            'spot buy' => ["Buy SOL/USDT spot\nBuy zone 145-150\nTarget 1: 160\nTarget 2: 170\nStop loss: 138",
                ['symbol' => 'SOL/USDT', 'market_type' => 'SPOT', 'side' => 'buy', 'entry' => '145', 'entry_max' => '150', 'stop_loss' => '138', 'targets' => ['160', '170'], 'leverage' => '1']],
            'forex sell' => ['EURUSD SELL @ 1.0850 TP 1.0800 SL 1.0900',
                ['symbol' => 'EUR/USD', 'market_type' => 'FOREX', 'side' => 'sell', 'entry' => '1.085', 'entry_max' => null, 'stop_loss' => '1.09', 'targets' => ['1.08'], 'leverage' => '1']],
            'gold market' => ["XAUUSD buy now\nTP: 2380\nSL: 2330",
                ['symbol' => 'XAU/USD', 'market_type' => 'CFD', 'side' => 'buy', 'entry' => null, 'entry_max' => null, 'stop_loss' => '2330', 'targets' => ['2380'], 'leverage' => '1']],
        ];
    }

    #[DataProvider('validMessages')]
    public function test_parses_supported_formats(string $message, array $expected): void
    {
        $this->assertSame($expected, app(SignalParserService::class)->parse($message)->toArray());
    }

    public static function ambiguousMessages(): array
    {
        return [
            'no direction' => ['BTC/USDT entry 67000 TP 68000 SL 66000'],
            'both directions' => ['BUY or SELL BTC/USDT entry 67000 TP 68000 SL 66000'],
            'two instruments' => ['BUY BTC/USDT and ETH/USDT entry 67000 TP 68000 SL 66000'],
            'unknown instrument' => ['BUY DOGE/USDT entry 0.1 TP 0.2 SL 0.05'],
            'no stop loss' => ['BUY BTC/USDT entry 67000 TP 68000'],
            'no target' => ['BUY BTC/USDT entry 67000 SL 66000'],
            'no entry' => ['BUY BTC/USDT TP 68000 SL 66000'],
            'target below buy entry' => ['BUY ETH/USDT spot entry 3500 TP 3400 SL 3300'],
            'stop above buy entry' => ['BUY ETH/USDT spot entry 3500 TP 3600 SL 3550'],
            'leverage on spot only' => ['BUY SOL/USDT entry 150 TP 160 SL 140 leverage 5x'],
            'chatter' => ['Good morning traders! Market looks bullish today.'],
        ];
    }

    #[DataProvider('ambiguousMessages')]
    public function test_rejects_ambiguous_messages(string $message): void
    {
        $this->expectException(AmbiguousSignal::class);
        app(SignalParserService::class)->parse($message);
    }
}
