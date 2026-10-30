<?php

namespace App\Modules\ChartAnalysis;

use App\Models\MarketCandle;
use App\Models\PriceAlert;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Decimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AlertManager
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function rules(): array
    {
        return [
            'symbol_id' => ['required', Rule::exists('trading_symbols', 'id')->where('is_active', true)],
            'condition' => ['required', Rule::in(PriceAlert::CONDITIONS)],
            'target' => ['required', 'numeric', 'gt:0'],
            'interval' => ['nullable', Rule::in(MarketCandle::INTERVALS)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function create(User $user, array $data): PriceAlert
    {
        $limit = $this->subscriptions->limit($user, 'alerts.max');
        $active = PriceAlert::where('user_id', $user->id)->where('status', 'active')->count();
        if ($limit !== -1 && $active >= $limit) {
            throw ValidationException::withMessages(['symbol_id' => "Your plan allows $limit active alerts."]);
        }
        if (str_starts_with($data['condition'], 'rsi_') && ! $this->subscriptions->hasFeature($user, 'charts.advanced')) {
            throw ValidationException::withMessages(['condition' => 'Indicator alerts require a plan with advanced charts.']);
        }

        return PriceAlert::create([
            'user_id' => $user->id,
            'symbol_id' => $data['symbol_id'],
            'condition' => $data['condition'],
            'target' => Decimal::str((string) $data['target']),
            'interval' => $data['interval'] ?? '1h',
            'note' => $data['note'] ?? null,
        ]);
    }
}
