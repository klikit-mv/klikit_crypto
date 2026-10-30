<?php

namespace App\Services\Trading;

use App\Models\Position;
use App\Models\Trade;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Trading\Exceptions\AdapterException;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Services\Trading\MarketData\MarketDataService;
use App\Support\Decimal;
use Brick\Math\BigDecimal;

/**
 * Shared portfolio and reporting layer across crypto and Forex, valued in USD.
 */
class PortfolioService
{
    public function __construct(
        private readonly PriceConverter $converter,
        private readonly MarketDataService $marketData,
    ) {}

    /**
     * @return array{wallets: list<array>, positions: list<array>, total_usd: string, available_usd: string, unrealized_pnl_usd: string, realized_pnl_usd: string}
     */
    public function summary(User $user, string $mode = 'paper'): array
    {
        $walletType = $mode === 'paper' ? 'paper' : 'spot';
        $total = BigDecimal::zero();
        $available = BigDecimal::zero();
        $wallets = [];

        foreach (Wallet::where('user_id', $user->id)->where('wallet_type', $walletType)->orderBy('currency')->get() as $wallet) {
            $usd = $this->safeUsd($wallet->balance, $wallet->currency);
            $availableUsd = $this->safeUsd($wallet->available(), $wallet->currency);
            $total = $total->plus($usd ?? BigDecimal::zero());
            $available = $available->plus($availableUsd ?? BigDecimal::zero());
            $wallets[] = [
                'currency' => $wallet->currency,
                'balance' => Decimal::display($wallet->balance),
                'locked' => Decimal::display($wallet->locked_balance),
                'available' => Decimal::display($wallet->available()),
                'value_usd' => $usd !== null ? Decimal::display($usd, 2) : null,
            ];
        }

        $unrealized = BigDecimal::zero();
        $positions = [];
        foreach (Position::where('user_id', $user->id)->where('mode', $mode)->where('status', 'open')->with('symbol')->get() as $position) {
            $pnl = $this->unrealizedPnl($position);
            $pnlUsd = $pnl !== null ? $this->safeUsd($pnl, $position->margin_currency) : null;
            $unrealized = $unrealized->plus($pnlUsd ?? BigDecimal::zero());
            $positions[] = [
                'id' => $position->id,
                'symbol' => $position->symbol->symbol,
                'market_type' => $position->symbol->market_type,
                'side' => $position->side,
                'quantity' => Decimal::display($position->quantity),
                'average_price' => Decimal::display($position->average_price),
                'leverage' => Decimal::display($position->leverage, 2),
                'margin' => Decimal::display($position->margin, 2),
                'unrealized_pnl' => $pnl !== null ? Decimal::display($pnl, 2) : null,
                'currency' => $position->margin_currency,
            ];
        }

        $realized = Trade::where('user_id', $user->id)->where('mode', $mode)->whereNotNull('pnl')->get(['pnl', 'pnl_currency'])
            ->reduce(fn (BigDecimal $sum, Trade $t) => $sum->plus($this->safeUsd($t->pnl, $t->pnl_currency) ?? BigDecimal::zero()), BigDecimal::zero());

        return [
            'wallets' => $wallets,
            'positions' => $positions,
            'total_usd' => Decimal::display($total->plus($unrealized), 2),
            'available_usd' => Decimal::display($available, 2),
            'unrealized_pnl_usd' => Decimal::display($unrealized, 2),
            'realized_pnl_usd' => Decimal::display($realized, 2),
        ];
    }

    public function unrealizedPnl(Position $position): ?BigDecimal
    {
        try {
            $ticker = $this->marketData->ticker($position->symbol);
        } catch (AdapterException) {
            return null;
        }
        $mark = Decimal::of($position->side === 'long' ? $ticker->bid : $ticker->ask);
        $direction = $position->side === 'long' ? 1 : -1;

        return Decimal::mul($mark->minus(Decimal::of($position->average_price)), $position->quantity, $position->symbol->contract_size)
            ->multipliedBy($direction);
    }

    private function safeUsd(BigDecimal|string $amount, string $currency): ?BigDecimal
    {
        try {
            return $this->converter->toUsd($amount, $currency);
        } catch (RiskViolation) {
            return null;
        }
    }
}
