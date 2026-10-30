<?php

namespace App\Services\Trading;

use App\Models\Order;
use App\Models\OrderExecution;
use App\Models\Position;
use App\Models\Trade;
use App\Models\Wallet;
use App\Services\Accounting\LedgerService;
use App\Services\Accounting\WalletService;
use App\Services\Trading\Data\ExecutionResult;
use App\Services\Trading\Data\Fill;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Applies venue results to the platform: executions, order state, trades, positions and,
 * for paper accounts, the double-entry ledger. Live accounts are non-custodial: their funds
 * stay at the exchange/broker, so no ledger postings are made for them.
 */
class SettlementService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly WalletService $wallets,
    ) {}

    public function apply(Order $order, ExecutionResult $result): Order
    {
        return DB::transaction(function () use ($order, $result) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('symbol', 'user')->firstOrFail();
            if (in_array($order->status, [Order::FILLED, Order::CANCELLED, Order::REJECTED, Order::FAILED], true)) {
                return $order; // terminal states are immutable
            }

            foreach ($result->fills as $fill) {
                if ($fill->externalTradeId && $order->executions()->where('external_trade_id', $fill->externalTradeId)->exists()) {
                    continue; // already applied during an earlier sync
                }
                $this->applyFill($order, $fill);
            }

            $filled = Decimal::of($order->filled_quantity);
            $status = match (true) {
                $filled->isGreaterThanOrEqualTo(Decimal::of($order->quantity)) => Order::FILLED,
                in_array($result->status, [Order::CANCELLED, Order::REJECTED, Order::FAILED], true) => $result->status,
                $filled->isPositive() => Order::PARTIALLY_FILLED,
                default => Order::OPEN,
            };

            $order->forceFill([
                'status' => $status,
                'external_order_id' => $result->externalOrderId ?? $order->external_order_id,
                'reject_reason' => $result->message && in_array($status, [Order::REJECTED, Order::FAILED], true) ? $result->message : $order->reject_reason,
            ]);

            if (! $order->isActive()) {
                $this->releaseReservation($order, Decimal::of($order->reserved_amount));
            }
            $order->save();

            return $order;
        });
    }

    public function closePosition(Position $position, Fill $fill): Position
    {
        return DB::transaction(function () use ($position, $fill) {
            $position = Position::whereKey($position->id)->lockForUpdate()->with('symbol', 'user')->firstOrFail();
            if ($position->status !== 'open') {
                return $position;
            }

            $symbol = $position->symbol;
            $entry = Decimal::of($position->average_price);
            $exit = Decimal::of($fill->price);
            $direction = $position->side === 'long' ? 1 : -1;
            $gross = Decimal::mul($exit->minus($entry), $position->quantity, $symbol->contract_size)->multipliedBy($direction);
            $margin = Decimal::of($position->margin);
            // Isolated margin: a position can never lose more than the margin assigned to it.
            if ($gross->isNegative() && $gross->abs()->isGreaterThan($margin)) {
                $gross = $margin->negated();
            }
            $fee = Decimal::of($fill->fee);
            $net = $gross->minus($fee);

            if ($position->mode === 'paper') {
                $wallet = $this->wallet($position->user_id, $position->margin_currency);
                $this->wallets->unlock($wallet, $margin);
                $lines = [['account' => $this->ledger->systemAccount('fees', $wallet->currency, 'paper'), 'credit' => $fee]];
                $lines[] = ['account' => $wallet->ledgerAccount, 'debit' => $fee];
                $lines[] = $gross->isNegative()
                    ? ['account' => $wallet->ledgerAccount, 'debit' => $gross->abs()]
                    : ['account' => $wallet->ledgerAccount, 'credit' => $gross];
                $lines[] = $gross->isNegative()
                    ? ['account' => $this->ledger->systemAccount('pnl', $wallet->currency, 'paper'), 'credit' => $gross->abs()]
                    : ['account' => $this->ledger->systemAccount('pnl', $wallet->currency, 'paper'), 'debit' => $gross];
                $this->ledger->post('pnl', $lines, $position, "Close {$position->side} {$symbol->symbol}");
            }

            Trade::where('position_id', $position->id)->whereNull('closed_at')->update(['closed_at' => now()]);
            Trade::create([
                'user_id' => $position->user_id,
                'position_id' => $position->id,
                'symbol_id' => $symbol->id,
                'mode' => $position->mode,
                'side' => $position->side,
                'quantity' => Decimal::str($position->quantity),
                'entry_price' => Decimal::str($entry),
                'exit_price' => Decimal::str($exit),
                'fee' => Decimal::str($fee),
                'pnl' => Decimal::str($net),
                'pnl_currency' => $position->margin_currency,
                'closed_at' => now(),
            ]);

            $position->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'realized_pnl' => Decimal::str(Decimal::of($position->realized_pnl)->plus($net)),
            ])->save();

            return $position;
        });
    }

    private function applyFill(Order $order, Fill $fill): void
    {
        $symbol = $order->symbol;
        $quantity = Decimal::of($fill->quantity);
        $price = Decimal::of($fill->price);
        $fee = Decimal::of($fill->fee);

        OrderExecution::create([
            'order_id' => $order->id,
            'execution_price' => Decimal::str($price),
            'quantity' => Decimal::str($quantity),
            'fee' => Decimal::str($fee),
            'fee_currency' => $fill->feeCurrency ?: $symbol->quote_currency,
            'external_trade_id' => $fill->externalTradeId,
            'executed_at' => now(),
        ]);

        $previous = Decimal::of($order->filled_quantity);
        $total = $previous->plus($quantity);
        $average = $previous->isZero()
            ? $price
            : Decimal::div(Decimal::mul($order->average_fill_price, $previous)->plus(Decimal::mul($price, $quantity)), $total);
        $order->forceFill([
            'filled_quantity' => Decimal::str($total),
            'average_fill_price' => Decimal::str($average),
        ]);

        if ($symbol->isSpot()) {
            $this->settleSpotFill($order, $quantity, $price, $fee);
        } else {
            $this->settleMarginFill($order, $quantity, $price, $fee);
        }
    }

    private function settleSpotFill(Order $order, BigDecimal $quantity, BigDecimal $price, BigDecimal $fee): void
    {
        $symbol = $order->symbol;
        $cost = Decimal::mul($quantity, $price, $symbol->contract_size);

        Trade::create([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'symbol_id' => $symbol->id,
            'mode' => $order->mode,
            'side' => $order->side,
            'quantity' => Decimal::str($quantity),
            'entry_price' => Decimal::str($price),
            'fee' => Decimal::str($fee),
        ]);

        if ($order->mode !== 'paper') {
            return;
        }

        $quote = $this->wallet($order->user_id, $symbol->quote_currency);
        $base = $this->wallet($order->user_id, $symbol->base_currency);
        $clearingQuote = $this->ledger->systemAccount('clearing', $symbol->quote_currency, 'paper');
        $clearingBase = $this->ledger->systemAccount('clearing', $symbol->base_currency, 'paper');
        $fees = $this->ledger->systemAccount('fees', $symbol->quote_currency, 'paper');

        if ($order->side === 'buy') {
            $this->consumeReservation($order, $cost->plus($fee));
            $lines = [
                ['account' => $quote->ledgerAccount, 'debit' => $cost->plus($fee)],
                ['account' => $clearingQuote, 'credit' => $cost],
                ['account' => $fees, 'credit' => $fee],
                ['account' => $clearingBase, 'debit' => $quantity],
                ['account' => $base->ledgerAccount, 'credit' => $quantity],
            ];
        } else {
            $this->consumeReservation($order, $quantity);
            $lines = [
                ['account' => $base->ledgerAccount, 'debit' => $quantity],
                ['account' => $clearingBase, 'credit' => $quantity],
                ['account' => $clearingQuote, 'debit' => $cost],
                ['account' => $quote->ledgerAccount, 'credit' => $cost->minus($fee)],
                ['account' => $fees, 'credit' => $fee],
            ];
        }

        $this->ledger->post('trade', $lines, $order, strtoupper($order->side)." {$symbol->symbol}");
    }

    private function settleMarginFill(Order $order, BigDecimal $quantity, BigDecimal $price, BigDecimal $fee): void
    {
        $symbol = $order->symbol;
        $side = $order->side === 'buy' ? 'long' : 'short';
        $margin = Decimal::div(Decimal::mul($quantity, $price, $symbol->contract_size), $order->leverage);

        if ($order->mode === 'paper') {
            $wallet = $this->wallet($order->user_id, $symbol->quote_currency);
            // The margin part of the order's reservation stays locked and moves to the position;
            // the fee part is released and charged.
            $transferred = Decimal::min($margin, $order->reserved_amount);
            $order->reserved_amount = Decimal::str(Decimal::of($order->reserved_amount)->minus($transferred));
            $extra = $margin->minus($transferred);
            if ($extra->isPositive()) {
                $this->wallets->lock($wallet, $extra);
            }
            $this->consumeReservation($order, $fee);
            $this->ledger->post('fee', [
                ['account' => $wallet->ledgerAccount, 'debit' => $fee],
                ['account' => $this->ledger->systemAccount('fees', $symbol->quote_currency, 'paper'), 'credit' => $fee],
            ], $order, "Open {$side} {$symbol->symbol} fee");
        }

        $position = Position::where([
            'user_id' => $order->user_id, 'symbol_id' => $symbol->id, 'mode' => $order->mode, 'side' => $side, 'status' => 'open',
        ])->lockForUpdate()->first();

        if ($position) {
            $oldQty = Decimal::of($position->quantity);
            $newQty = $oldQty->plus($quantity);
            $position->forceFill([
                'quantity' => Decimal::str($newQty),
                'average_price' => Decimal::str(Decimal::div(Decimal::mul($position->average_price, $oldQty)->plus(Decimal::mul($price, $quantity)), $newQty)),
                'margin' => Decimal::str(Decimal::of($position->margin)->plus($margin)),
                'stop_loss' => $order->stop_loss ?? $position->stop_loss,
                'take_profit' => $order->take_profit ?? $position->take_profit,
            ])->save();
        } else {
            $position = Position::create([
                'user_id' => $order->user_id,
                'symbol_id' => $symbol->id,
                'mode' => $order->mode,
                'side' => $side,
                'quantity' => Decimal::str($quantity),
                'average_price' => Decimal::str($price),
                'leverage' => $order->leverage,
                'margin' => Decimal::str($margin),
                'margin_currency' => $symbol->quote_currency,
                'stop_loss' => $order->stop_loss,
                'take_profit' => $order->take_profit,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        }

        Trade::create([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'position_id' => $position->id,
            'symbol_id' => $symbol->id,
            'mode' => $order->mode,
            'side' => $side,
            'quantity' => Decimal::str($quantity),
            'entry_price' => Decimal::str($price),
            'fee' => Decimal::str($fee),
        ]);
    }

    /** Release up to $amount of the order's reservation (the ledger posting then spends it). */
    private function consumeReservation(Order $order, BigDecimal $amount): void
    {
        $release = Decimal::min($amount, $order->reserved_amount);
        $this->releaseReservation($order, $release);
    }

    private function releaseReservation(Order $order, BigDecimal $amount): void
    {
        if ($order->mode !== 'paper' || ! $amount->isPositive() || $order->reserved_currency === null) {
            return;
        }

        $this->wallets->unlock($this->wallet($order->user_id, $order->reserved_currency), $amount);
        $order->reserved_amount = Decimal::str(Decimal::of($order->reserved_amount)->minus($amount));
    }

    private function wallet(int $userId, string $currency): Wallet
    {
        return $this->wallets->walletFor($userId, $currency, 'paper');
    }
}
