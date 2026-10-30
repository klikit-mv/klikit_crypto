<?php

namespace App\Services\Trading;

use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Trade;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\Data\OrderIntent;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Support\Decimal;
use Brick\Math\BigDecimal;

/**
 * Single risk gate for manual, signal-driven and automated trading. Limits are evaluated in
 * USD equivalent. Throws RiskViolation describing the first rule that blocks the order.
 */
class RiskManagementService
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly PriceConverter $converter,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function profileFor(User $user): RiskProfile
    {
        return RiskProfile::firstOrCreate(['user_id' => $user->id], config('trading.default_risk_profile'));
    }

    /**
     * @param  BigDecimal  $referencePrice  limit price, or the current touch price for market orders
     */
    public function assess(OrderIntent $intent, BigDecimal $referencePrice): void
    {
        $user = $intent->user;
        $symbol = $intent->symbol;
        $profile = $this->profileFor($user);

        $this->check($user->isActive(), 'account_inactive', 'Your account is not active.');
        $this->check(! $this->settings->tradingHalted(), 'platform_halted', 'Trading is temporarily halted on the platform.');
        $this->check($profile->trading_enabled, 'user_trading_disabled', 'Trading is disabled for your account'.($profile->disabled_reason ? ": {$profile->disabled_reason}" : '.'));
        $this->check($symbol->is_active, 'symbol_inactive', "{$symbol->symbol} is not available for trading.");

        if ($intent->mode === 'live') {
            $this->check((bool) config('trading.live_trading_enabled'), 'live_disabled', 'Live trading is not enabled on this platform.');
            $this->check($this->subscriptions->hasFeature($user, 'trading.manual'), 'plan_required', 'Your plan does not include live trading.');
        } else {
            $this->check($this->subscriptions->hasFeature($user, 'trading.paper'), 'plan_required', 'Your plan does not include paper trading.');
        }

        $quantity = Decimal::of($intent->quantity);
        $this->check($quantity->isPositive(), 'invalid_quantity', 'Quantity must be positive.');
        $this->check($quantity->isGreaterThanOrEqualTo(Decimal::of($symbol->min_quantity)), 'min_quantity', "Minimum quantity for {$symbol->symbol} is ".Decimal::display($symbol->min_quantity).'.');

        // Leverage
        $leverage = Decimal::of($intent->leverage);
        if ($symbol->isSpot()) {
            $this->check($leverage->isEqualTo(1), 'leverage', 'Spot orders cannot use leverage.');
        } else {
            $max = Decimal::min(Decimal::min($profile->max_leverage, $symbol->max_leverage), $this->settings->limit('max_leverage'));
            $this->check($leverage->isGreaterThanOrEqualTo(1) && $leverage->isLessThanOrEqualTo($max), 'leverage', 'Maximum leverage allowed is '.Decimal::display($max).'x.');
        }

        // Stop-loss presence and direction
        $this->check(! $profile->require_stop_loss || $intent->stopLoss !== null, 'stop_loss_required', 'A stop-loss is required by your risk settings.');
        if ($intent->stopLoss !== null) {
            $stop = Decimal::of($intent->stopLoss);
            $valid = $intent->side === 'buy' ? $stop->isLessThan($referencePrice) : $stop->isGreaterThan($referencePrice);
            $this->check($valid, 'stop_loss_direction', 'Stop-loss must be below the entry price for buys and above it for sells.');
        }

        // Size limits (USD equivalent notional)
        $notionalUsd = $this->converter->toUsd(Decimal::mul($quantity, $referencePrice, $symbol->contract_size), $symbol->quote_currency);
        $this->check($notionalUsd->isLessThanOrEqualTo(Decimal::of($profile->max_amount_per_trade)), 'max_amount_per_trade', 'Order exceeds your maximum amount per trade ('.Decimal::display($profile->max_amount_per_trade, 2).' USD).');
        $this->check($notionalUsd->isLessThanOrEqualTo(Decimal::of($this->settings->limit('max_order_notional'))), 'platform_max_order', 'Order exceeds the platform maximum order size.');

        // Daily realised loss
        $lossToday = $this->realisedLossTodayUsd($user, $intent->mode);
        $this->check($lossToday->isLessThan(Decimal::of($profile->max_daily_loss)), 'max_daily_loss', 'Daily loss limit reached; trading resumes tomorrow (UTC).');

        // Open positions and exposure apply to margined (non-spot) instruments
        if (! $symbol->isSpot()) {
            $open = Position::where('user_id', $user->id)->where('mode', $intent->mode)->where('status', 'open')->with('symbol')->get();
            $maxPositions = min((int) $profile->max_open_positions, (int) $this->settings->limit('max_open_positions'));
            $this->check($open->count() < $maxPositions, 'max_open_positions', "You already have the maximum of $maxPositions open positions.");

            $exposure = $open->reduce(
                fn (BigDecimal $sum, Position $p) => $sum->plus($this->converter->toUsd(Decimal::mul($p->quantity, $p->average_price, $p->symbol->contract_size), $p->symbol->quote_currency)),
                BigDecimal::zero(),
            );
            $this->check($exposure->plus($notionalUsd)->isLessThanOrEqualTo(Decimal::of($profile->max_total_exposure)), 'max_total_exposure', 'Order would exceed your maximum total exposure.');
        }
    }

    public function realisedLossTodayUsd(User $user, string $mode): BigDecimal
    {
        return Trade::where('user_id', $user->id)
            ->where('mode', $mode)
            ->where('closed_at', '>=', now()->utc()->startOfDay())
            ->where('pnl', '<', 0)
            ->get(['pnl', 'pnl_currency'])
            ->reduce(fn (BigDecimal $sum, Trade $t) => $sum->plus($this->converter->toUsd(Decimal::of($t->pnl)->abs(), $t->pnl_currency)), BigDecimal::zero());
    }

    private function check(bool $condition, string $rule, string $message): void
    {
        if (! $condition) {
            throw new RiskViolation($rule, $message);
        }
    }
}
