<?php

namespace App\Services\Trading;

use App\Jobs\ExecuteOrderJob;
use App\Models\Order;
use App\Models\Position;
use App\Services\Access\AuditLogger;
use App\Services\Accounting\WalletService;
use App\Services\Trading\Data\ExecutionResult;
use App\Services\Trading\Data\OrderIntent;
use App\Services\Trading\Exceptions\AdapterException;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Services\Trading\MarketData\MarketDataService;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Order lifecycle: intent -> risk check -> reservation -> queued execution -> settlement.
 *
 * Laravel only creates validated, funded orders; the actual venue call runs in
 * ExecuteOrderJob on the dedicated trading queue.
 */
class TradingEngineService
{
    public function __construct(
        private readonly RiskManagementService $risk,
        private readonly WalletService $wallets,
        private readonly MarketDataService $marketData,
        private readonly ExecutionRouter $router,
        private readonly SettlementService $settlement,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Idempotent: repeating a request with the same key returns the original order.
     *
     * @throws RiskViolation (the rejected order is still persisted for the audit trail)
     */
    public function submit(OrderIntent $intent, string $idempotencyKey): Order
    {
        if ($existing = $this->findByKey($intent, $idempotencyKey)) {
            return $existing;
        }

        try {
            $order = DB::transaction(function () use ($intent, $idempotencyKey) {
                $referencePrice = $this->referencePrice($intent);
                $this->risk->assess($intent, $referencePrice);

                $order = $this->newOrder($intent, $idempotencyKey, Order::PENDING);
                if ($intent->mode === 'paper') {
                    [$currency, $amount] = $this->reservation($intent, $referencePrice);
                    $this->wallets->lock($this->wallets->walletFor($intent->user, $currency, 'paper'), $amount);
                    $order->forceFill(['reserved_currency' => $currency, 'reserved_amount' => Decimal::str($amount)]);
                }
                $order->save();

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($intent, $idempotencyKey);
        } catch (RiskViolation|AdapterException $e) {
            $rule = $e instanceof RiskViolation ? $e->rule : 'market_data';
            $rejected = $this->newOrder($intent, $idempotencyKey, Order::REJECTED);
            $rejected->reject_reason = Str::limit($e->getMessage(), 250);
            try {
                $rejected->save();
            } catch (UniqueConstraintViolationException) {
                return $this->findByKey($intent, $idempotencyKey);
            }
            $this->audit->log('order.rejected', $intent->user, $rejected, ['rule' => $rule]);

            throw $e instanceof RiskViolation ? $e : new RiskViolation($rule, $e->getMessage());
        }

        $this->audit->log('order.submitted', $intent->user, $order, ['source' => $intent->source, 'mode' => $intent->mode]);
        ExecuteOrderJob::dispatch($order->id)->onQueue(config('trading.queue'))->afterCommit();

        return $order->refresh();
    }

    /** Called by ExecuteOrderJob on a trading worker. */
    public function execute(Order $order): Order
    {
        $claimed = Order::whereKey($order->id)->where('status', Order::PENDING)->update(['status' => Order::OPEN]);
        if (! $claimed) {
            return $order->refresh(); // already executed or cancelled
        }
        $order->refresh();

        try {
            $result = $this->router->forOrder($order)->placeOrder($order);
        } catch (AdapterException $e) {
            $result = new ExecutionResult(Order::FAILED, message: $e->getMessage());
        }

        return $this->settlement->apply($order, $result);
    }

    /** Re-check an open order with its venue (limit orders, reconciliation). */
    public function sync(Order $order): Order
    {
        if (! in_array($order->status, [Order::OPEN, Order::PARTIALLY_FILLED], true)) {
            return $order;
        }

        return $this->settlement->apply($order, $this->router->forOrder($order)->syncOrder($order));
    }

    public function cancel(Order $order): Order
    {
        if (! $order->isActive()) {
            throw new RiskViolation('not_cancellable', 'Only pending or open orders can be cancelled.');
        }

        if ($order->status !== Order::PENDING && ! $this->router->forOrder($order)->cancelOrder($order)) {
            throw new AdapterException('The venue refused to cancel the order.');
        }

        $order = $this->settlement->apply($order, new ExecutionResult(Order::CANCELLED));
        $this->audit->log('order.cancelled', $order->user, $order);

        return $order;
    }

    public function closePosition(Position $position): Position
    {
        if ($position->status !== 'open') {
            throw new RiskViolation('position_closed', 'This position is already closed.');
        }

        $fill = $this->router->forPosition($position)->closePosition($position);
        $position = $this->settlement->closePosition($position, $fill);
        $this->audit->log('position.closed', $position->user, $position, ['pnl' => (string) $position->realized_pnl]);

        return $position;
    }

    private function referencePrice(OrderIntent $intent): BigDecimal
    {
        if ($intent->type === 'limit' && $intent->price !== null) {
            return Decimal::of($intent->price);
        }

        $ticker = $this->marketData->ticker($intent->symbol);

        return Decimal::of($intent->side === 'buy' ? $ticker->ask : $ticker->bid);
    }

    /**
     * @return array{0: string, 1: BigDecimal} currency and amount to lock
     */
    private function reservation(OrderIntent $intent, BigDecimal $price): array
    {
        $symbol = $intent->symbol;
        $buffer = $intent->type === 'market' ? Decimal::of(config('trading.market_order_buffer'))->plus(1) : BigDecimal::one();
        $feeRate = Decimal::of(config('trading.paper.fee_rate'));
        $notional = Decimal::mul($intent->quantity, $price, $symbol->contract_size, $buffer);

        if ($symbol->isSpot()) {
            return $intent->side === 'buy'
                ? [$symbol->quote_currency, Decimal::mul($notional, $feeRate->plus(1))]
                : [$symbol->base_currency, Decimal::of($intent->quantity)];
        }

        $margin = Decimal::div($notional, $intent->leverage);

        return [$symbol->quote_currency, $margin->plus(Decimal::mul($notional, $feeRate))];
    }

    private function newOrder(OrderIntent $intent, string $key, string $status): Order
    {
        return new Order([
            'uuid' => (string) Str::uuid(),
            'user_id' => $intent->user->id,
            'symbol_id' => $intent->symbol->id,
            'exchange_account_id' => $intent->exchangeAccountId,
            'broker_account_id' => $intent->brokerAccountId,
            'idempotency_key' => $key,
            'mode' => $intent->mode,
            'source' => $intent->source,
            'side' => $intent->side,
            'type' => $intent->type,
            'quantity' => Decimal::str($intent->quantity),
            'price' => $intent->price !== null ? Decimal::str($intent->price) : null,
            'stop_price' => $intent->stopPrice !== null ? Decimal::str($intent->stopPrice) : null,
            'stop_loss' => $intent->stopLoss !== null ? Decimal::str($intent->stopLoss) : null,
            'take_profit' => $intent->takeProfit !== null ? Decimal::str($intent->takeProfit) : null,
            'leverage' => $intent->leverage,
            'status' => $status,
        ]);
    }

    private function findByKey(OrderIntent $intent, string $key): ?Order
    {
        return Order::where('user_id', $intent->user->id)->where('idempotency_key', $key)->first();
    }
}
