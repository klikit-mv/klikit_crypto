<?php

namespace App\Services\Trading\MarketData;

use App\Models\MarketPrice;
use App\Models\TradingSymbol;
use App\Services\Analysis\CandleService;
use App\Services\Trading\Contracts\MarketDataProvider;
use App\Services\Trading\Data\Ticker;
use App\Services\Trading\Exceptions\AdapterException;
use App\Support\Decimal;
use Illuminate\Support\Collection;

class MarketDataService
{
    /** Prices older than this are considered stale and are not used for execution. */
    public const MAX_PRICE_AGE_MINUTES = 15;

    /** @var list<MarketDataProvider> */
    private array $providers;

    public function __construct(private readonly CandleService $candles, MarketDataProvider ...$providers)
    {
        $this->providers = $providers;
    }

    public function ticker(TradingSymbol $symbol): Ticker
    {
        $price = MarketPrice::where('symbol_id', $symbol->id)->latest('timestamp')->first();
        if ($price === null) {
            throw new AdapterException("No market price available for {$symbol->symbol}.");
        }
        if ($price->timestamp->lt(now()->subMinutes(self::MAX_PRICE_AGE_MINUTES)) && ! config('trading.allow_stale_prices', false)) {
            throw new AdapterException("Market price for {$symbol->symbol} is stale.");
        }

        return new Ticker($symbol->symbol, $price->bid, $price->ask, $price->price, $price->change_24h, $price->volume_24h);
    }

    /**
     * Pull fresh tickers from every provider that supports the given symbols.
     *
     * @param  Collection<int, TradingSymbol>  $symbols
     * @return int number of prices stored
     */
    public function refresh(Collection $symbols): int
    {
        $stored = 0;
        foreach ($this->providers as $provider) {
            $supported = $symbols->filter(fn (TradingSymbol $s) => $provider->supports($s));
            if ($supported->isEmpty()) {
                continue;
            }

            foreach ($provider->tickers($supported) as $symbolId => $ticker) {
                $this->store($symbolId, $ticker, $provider->name());
                $stored++;
            }
        }

        return $stored;
    }

    public function store(int $symbolId, Ticker $ticker, string $source): MarketPrice
    {
        $this->candles->recordTick($symbolId, $ticker->last);

        return MarketPrice::create([
            'symbol_id' => $symbolId,
            'source' => $source,
            'bid' => Decimal::str($ticker->bid),
            'ask' => Decimal::str($ticker->ask),
            'price' => Decimal::str($ticker->last),
            'change_24h' => $ticker->change24h,
            'volume_24h' => $ticker->volume24h !== null ? Decimal::str($ticker->volume24h) : null,
            'timestamp' => now(),
        ]);
    }
}
