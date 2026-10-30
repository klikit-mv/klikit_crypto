<?php

namespace App\Services\Signals;

use App\Models\SignalExecution;
use App\Models\SignalLog;
use App\Models\SignalSubscription;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Notifications\SignalAwaitingApproval;
use App\Services\Access\AuditLogger;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\Data\OrderIntent;
use App\Services\Trading\Exceptions\AdapterException;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Services\Trading\MarketData\MarketDataService;
use App\Services\Trading\PriceConverter;
use App\Services\Trading\TradingEngineService;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Signal pipeline: channel message -> parser -> validation -> per-subscriber execution
 * (manual approval or automatic) -> trading engine (same risk checks as manual orders).
 */
class SignalService
{
    public function __construct(
        private readonly SignalParserService $parser,
        private readonly SubscriptionService $subscriptions,
        private readonly TradingEngineService $engine,
        private readonly MarketDataService $marketData,
        private readonly PriceConverter $converter,
        private readonly AuditLogger $audit,
    ) {}

    /** Returns the signal, or null when the message was not a valid signal (it is still logged). */
    public function ingest(TelegramChannel $channel, string $text, ?string $messageId = null): ?TelegramSignal
    {
        if ($messageId !== null && TelegramSignal::where('channel_id', $channel->id)->where('message_id', $messageId)->exists()) {
            return null; // Telegram re-delivers updates; never process a message twice
        }

        try {
            $parsed = $this->parser->parse($text);
        } catch (AmbiguousSignal $e) {
            SignalLog::create(['channel_id' => $channel->id, 'raw_message' => $text, 'parsed_data' => ['error' => $e->getMessage()], 'result' => 'ambiguous']);

            return null;
        }

        $signal = DB::transaction(function () use ($channel, $text, $messageId, $parsed) {
            $signal = TelegramSignal::create([
                'channel_id' => $channel->id,
                'symbol_id' => $parsed->symbol->id,
                'symbol' => $parsed->symbol->symbol,
                'market_type' => $parsed->symbol->market_type,
                'message_id' => $messageId,
                'signal_type' => $parsed->side,
                'entry_price' => $parsed->entry !== null ? Decimal::str($parsed->entry) : null,
                'entry_price_max' => $parsed->entryMax !== null ? Decimal::str($parsed->entryMax) : null,
                'stop_loss' => Decimal::str($parsed->stopLoss),
                'leverage' => $parsed->leverage,
                'status' => 'valid',
                'received_at' => now(),
                'expires_at' => now()->addMinutes($channel->signal_ttl_minutes),
            ]);
            foreach ($parsed->targets as $i => $target) {
                $signal->targets()->create(['target_number' => $i + 1, 'target_price' => Decimal::str($target)]);
            }
            SignalLog::create(['signal_id' => $signal->id, 'channel_id' => $channel->id, 'raw_message' => $text, 'parsed_data' => $parsed->toArray(), 'result' => 'parsed']);

            return $signal;
        });

        $this->distribute($signal);

        return $signal;
    }

    private function distribute(TelegramSignal $signal): void
    {
        $subscriptions = SignalSubscription::where('channel_id', $signal->channel_id)->where('is_active', true)->with('user')->get();

        foreach ($subscriptions as $subscription) {
            $user = $subscription->user;
            if (! $user->isActive() || ! $this->subscriptions->hasFeature($user, 'signals.telegram')) {
                continue;
            }

            $limit = $this->subscriptions->limit($user, 'signals.daily_limit');
            $today = SignalExecution::where('user_id', $user->id)->where('created_at', '>=', now()->utc()->startOfDay())->whereNot('status', 'skipped')->count();
            $skipped = $limit !== -1 && $today >= $limit;

            $execution = SignalExecution::create([
                'signal_id' => $signal->id,
                'user_id' => $user->id,
                'status' => $skipped ? 'skipped' : 'pending_approval',
                'reason' => $skipped ? 'Daily signal limit reached' : null,
            ]);

            if ($skipped) {
                continue;
            }
            if ($subscription->execution_mode === 'auto') {
                $this->execute($execution, $subscription);
            } else {
                $user->notify(new SignalAwaitingApproval($signal));
            }
        }
    }

    public function approve(SignalExecution $execution): SignalExecution
    {
        if ($execution->status !== 'pending_approval') {
            throw new RiskViolation('signal_not_pending', 'This signal is no longer awaiting approval.');
        }
        if ($execution->signal->expires_at?->isPast()) {
            $execution->update(['status' => 'expired', 'reason' => 'Signal expired before approval']);
            throw new RiskViolation('signal_expired', 'This signal has expired.');
        }

        $subscription = SignalSubscription::where(['user_id' => $execution->user_id, 'channel_id' => $execution->signal->channel_id])->firstOrFail();
        $execution->update(['status' => 'approved']);

        return $this->execute($execution, $subscription);
    }

    public function reject(SignalExecution $execution): void
    {
        if ($execution->status === 'pending_approval') {
            $execution->update(['status' => 'rejected', 'reason' => 'Rejected by user']);
        }
    }

    public function expireStale(): int
    {
        $ids = TelegramSignal::where('expires_at', '<', now())->pluck('id');

        return SignalExecution::whereIn('signal_id', $ids)->where('status', 'pending_approval')
            ->update(['status' => 'expired', 'reason' => 'Signal expired before approval']);
    }

    /** Converts the signal into an order through the normal trading engine. */
    private function execute(SignalExecution $execution, SignalSubscription $subscription): SignalExecution
    {
        $signal = $execution->signal;
        $symbol = $signal->tradingSymbol;
        $user = $execution->user;

        try {
            $ticker = $this->marketData->ticker($symbol);
            $touch = Decimal::of($signal->signal_type === 'buy' ? $ticker->ask : $ticker->bid);

            // Market order when price is inside the entry zone (or none given); otherwise a
            // limit order at the zone edge, so we never chase price beyond the signal.
            [$type, $price] = ['market', null];
            if ($signal->entry_price !== null) {
                $low = Decimal::of($signal->entry_price);
                $high = Decimal::of($signal->entry_price_max ?? $signal->entry_price);
                $inZone = $touch->isGreaterThanOrEqualTo($low) && $touch->isLessThanOrEqualTo($high);
                if (! $inZone) {
                    [$type, $price] = ['limit', $signal->signal_type === 'buy' ? $high : $low];
                }
            }
            $reference = $price ?? $touch;

            $usdPerUnit = $this->converter->toUsd(Decimal::mul($reference, $symbol->contract_size), $symbol->quote_currency);
            $quantity = Decimal::div($subscription->amount_per_signal, $usdPerUnit)->toScale($symbol->quantity_precision, RoundingMode::DOWN);
            $leverage = $symbol->isSpot() ? '1' : (string) Decimal::min($signal->leverage, $subscription->max_leverage);

            $order = $this->engine->submit(OrderIntent::fromArray($user, $symbol, [
                'side' => $signal->signal_type,
                'type' => $type,
                'quantity' => (string) $quantity,
                'price' => $price !== null ? (string) $price : null,
                'stop_loss' => $signal->stop_loss,
                'take_profit' => $signal->targets()->orderBy('target_number')->value('target_price'),
                'leverage' => $leverage,
                'mode' => 'paper',
            ], 'signal'), "signal-{$signal->id}");

            $execution->update(['order_id' => $order->id, 'status' => $order->status === 'rejected' ? 'failed' : 'executed', 'reason' => $order->reject_reason]);
        } catch (RiskViolation|AdapterException $e) {
            $execution->update(['status' => 'failed', 'reason' => $e->getMessage()]);
        }

        $this->audit->log('signal.execution', $user, $execution, ['status' => $execution->status]);

        return $execution;
    }
}
