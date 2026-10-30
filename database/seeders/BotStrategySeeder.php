<?php

namespace Database\Seeders;

use App\Models\BotStrategy;
use Illuminate\Database\Seeder;

/** Strategy catalogue for Phase 6 (trading bots); seeded now so bots can reference it. */
class BotStrategySeeder extends Seeder
{
    public function run(): void
    {
        $strategies = [
            ['Grid bot', 'grid', 'Places buy and sell orders at fixed intervals within a price range.', ['lower_price' => null, 'upper_price' => null, 'grid_count' => 10]],
            ['DCA bot', 'dca', 'Buys a fixed amount at regular intervals or on price drops.', ['order_amount' => null, 'interval_hours' => 24, 'safety_orders' => 3]],
            ['Scalping bot', 'scalping', 'Short-term trades on small price movements.', ['take_profit_pct' => 0.3, 'stop_loss_pct' => 0.2]],
            ['Trend-following bot', 'trend', 'Follows EMA crossovers.', ['fast_ema' => 12, 'slow_ema' => 26]],
            ['RSI strategy bot', 'rsi', 'Buys oversold and sells overbought RSI levels.', ['period' => 14, 'oversold' => 30, 'overbought' => 70]],
            ['MACD strategy bot', 'macd', 'Trades MACD signal-line crossovers.', ['fast' => 12, 'slow' => 26, 'signal' => 9]],
            ['Telegram signal bot', 'telegram_signal', 'Executes validated signals from connected Telegram channels.', ['auto_execute' => false]],
            ['Manual strategy bot', 'manual', 'User-defined rules.', []],
        ];

        foreach ($strategies as [$name, $type, $description, $configuration]) {
            BotStrategy::firstOrCreate(['strategy_type' => $type], ['name' => $name, 'description' => $description, 'configuration' => $configuration]);
        }
    }
}
