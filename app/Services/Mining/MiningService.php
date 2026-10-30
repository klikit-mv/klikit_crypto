<?php

namespace App\Services\Mining;

use App\Models\MiningDevice;
use App\Models\MiningEarning;
use App\Models\MiningExpense;
use App\Models\MiningPayout;
use App\Models\MiningPoolAccount;
use App\Models\MiningProfitability;
use App\Models\MiningStat;
use App\Models\MiningWorker;
use App\Models\User;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Services\Trading\PriceConverter;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monitoring and accounting for real mining hardware. The platform never "generates"
 * mining income: revenue comes only from pool reports (API or manual entry), and
 *
 *   net profit = mining revenue - electricity - pool fees - other costs
 */
class MiningService
{
    /** Workers that have not reported for this long are marked offline. */
    public const OFFLINE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly PriceConverter $converter,
        private readonly GenericJsonPoolClient $genericClient,
    ) {}

    /** @return array{0: MiningDevice, 1: string} the device and its plaintext agent token (shown once) */
    public function registerDevice(User $user, array $data): array
    {
        $token = 'mdt_'.Str::random(48);
        $device = MiningDevice::create($data + ['user_id' => $user->id, 'status' => 'offline', 'agent_token_hash' => hash('sha256', $token)]);

        return [$device, $token];
    }

    public function rotateToken(MiningDevice $device): string
    {
        $token = 'mdt_'.Str::random(48);
        $device->forceFill(['agent_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function deviceForToken(string $token): ?MiningDevice
    {
        return $token === '' ? null : MiningDevice::where('agent_token_hash', hash('sha256', $token))->first();
    }

    /**
     * @param  list<array{name: string, hashrate: numeric, temperature?: numeric|null, accepted?: int, rejected?: int}>  $workers
     */
    public function ingestStats(MiningDevice $device, array $workers): int
    {
        return DB::transaction(function () use ($device, $workers) {
            foreach ($workers as $w) {
                $worker = MiningWorker::firstOrCreate(['device_id' => $device->id, 'worker_name' => $w['name']]);
                $online = (float) $w['hashrate'] > 0;
                $worker->update(['status' => $online ? 'online' : 'idle', 'last_seen_at' => now()]);
                MiningStat::create([
                    'worker_id' => $worker->id,
                    'hashrate' => $w['hashrate'],
                    'temperature' => $w['temperature'] ?? null,
                    'accepted_shares' => $w['accepted'] ?? 0,
                    'rejected_shares' => $w['rejected'] ?? 0,
                    'recorded_at' => now(),
                ]);
            }
            $device->update(['status' => 'online', 'last_seen_at' => now()]);

            return count($workers);
        });
    }

    public function markOffline(): int
    {
        $cutoff = now()->subMinutes(self::OFFLINE_AFTER_MINUTES);
        MiningWorker::where('status', '!=', 'offline')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))->update(['status' => 'offline']);

        return MiningDevice::where('status', 'online')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))->update(['status' => 'offline']);
    }

    public function recordEarning(User $user, ?int $poolId, string $amount, string $currency, string $date, ?string $externalId = null): MiningEarning
    {
        return MiningEarning::updateOrCreate(
            ['user_id' => $user->id, 'pool_id' => $poolId, 'date' => $date, 'currency' => strtoupper($currency)],
            ['amount' => Decimal::str($amount), 'external_id' => $externalId],
        );
    }

    /** Pull earnings and payouts from the pool API. Returns the number of earning days stored. */
    public function syncPoolAccount(MiningPoolAccount $account, int $days = 7): int
    {
        $account->loadMissing('pool', 'user');
        if ($account->pool->api_type !== 'generic_json' || ! $account->pool->api_url) {
            return 0;
        }

        $data = $this->genericClient->fetch($account, now()->subDays($days)->startOfDay(), now());
        foreach ($data['earnings'] as $e) {
            $this->recordEarning($account->user, $account->pool_id, (string) $e['amount'], $e['currency'], Carbon::parse($e['date'])->toDateString(), $e['external_id'] ?? null);
        }
        foreach ($data['payouts'] as $p) {
            MiningPayout::firstOrCreate(
                ['user_id' => $account->user_id, 'pool_id' => $account->pool_id, 'transaction_hash' => $p['tx_hash'] ?? null, 'amount' => Decimal::str((string) $p['amount'])],
                ['currency' => strtoupper($p['currency']), 'paid_at' => isset($p['paid_at']) ? Carbon::parse($p['paid_at']) : now()],
            );
        }
        $account->update(['last_synced_at' => now()]);

        return count($data['earnings']);
    }

    /**
     * Compute a device's profitability for one day (USD). Revenue from the device's pool is
     * shared between the user's devices on that pool in proportion to their average hashrate.
     */
    public function computeDay(MiningDevice $device, Carbon $date): MiningProfitability
    {
        $device->loadMissing('pool');
        $start = $date->copy()->utc()->startOfDay();
        $end = $start->copy()->endOfDay();

        $avgHashrate = fn (MiningDevice $d) => (float) MiningStat::whereIn('worker_id', $d->workers()->pluck('id'))
            ->whereBetween('recorded_at', [$start, $end])->avg('hashrate');
        $uptimeHours = MiningStat::whereIn('worker_id', $device->workers()->pluck('id'))->whereBetween('recorded_at', [$start, $end])
            ->get(['recorded_at'])->map(fn ($s) => $s->recorded_at->format('H'))->unique()->count();

        $revenue = BigDecimal::zero();
        if ($device->pool_id) {
            $siblings = MiningDevice::where('user_id', $device->user_id)->where('pool_id', $device->pool_id)->get();
            $total = $siblings->sum(fn ($d) => $avgHashrate($d));
            $share = $total > 0 ? $avgHashrate($device) / $total : 0.0;

            foreach (MiningEarning::where(['user_id' => $device->user_id, 'pool_id' => $device->pool_id])->where('date', $start->toDateString())->get() as $earning) {
                $usd = $this->usd($earning->amount, $earning->currency);
                $revenue = $revenue->plus(Decimal::mul($usd, number_format($share, 12, '.', '')));
            }
        }

        $kwh = Decimal::div((string) ($device->power_watts * $uptimeHours), 1000);
        $electricity = Decimal::mul($kwh, $device->electricity_rate);
        $poolFees = Decimal::div(Decimal::mul($revenue, $device->pool?->fee_percent ?? '0'), 100);

        $expense = MiningExpense::firstOrNew(['device_id' => $device->id, 'date' => $start->toDateString()]);
        $expense->fill(['electricity_cost' => Decimal::str($electricity, 8), 'pool_fees' => Decimal::str($poolFees, 8), 'currency' => 'USD']);
        $expense->other_costs ??= '0';
        $expense->save();

        $expenses = $electricity->plus($poolFees)->plus(Decimal::of($expense->other_costs));

        return MiningProfitability::updateOrCreate(
            ['device_id' => $device->id, 'date' => $start->toDateString()],
            ['revenue' => Decimal::str($revenue, 8), 'expenses' => Decimal::str($expenses, 8), 'net_profit' => Decimal::str($revenue->minus($expenses), 8), 'currency' => 'USD'],
        );
    }

    public function addOtherCost(MiningDevice $device, string $date, string $amount): void
    {
        $expense = MiningExpense::firstOrNew(['device_id' => $device->id, 'date' => $date]);
        $expense->other_costs = Decimal::str(Decimal::of($expense->other_costs ?? '0')->plus(Decimal::of($amount)), 8);
        $expense->electricity_cost ??= '0';
        $expense->pool_fees ??= '0';
        $expense->save();
    }

    private function usd(string $amount, string $currency): BigDecimal
    {
        try {
            return $this->converter->toUsd($amount, $currency);
        } catch (RiskViolation) {
            return BigDecimal::zero(); // no price for this coin yet
        }
    }
}
