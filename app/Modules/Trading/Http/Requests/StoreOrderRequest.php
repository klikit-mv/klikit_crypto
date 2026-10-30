<?php

namespace App\Modules\Trading\Http\Requests;

use App\Models\TradingSymbol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    private const DECIMAL = 'regex:/^\d{1,18}(\.\d{1,18})?$/';

    public function rules(): array
    {
        $user = $this->user();

        return [
            'symbol_id' => ['required', 'integer', Rule::exists('trading_symbols', 'id')->where('is_active', true)->whereIn('market_type', $this->marketTypes())],
            'side' => ['required', Rule::in(['buy', 'sell'])],
            'type' => ['required', Rule::in(['market', 'limit'])],
            'quantity' => ['required', self::DECIMAL],
            'price' => ['required_if:type,limit', 'nullable', self::DECIMAL],
            'stop_loss' => ['nullable', self::DECIMAL],
            'take_profit' => ['nullable', self::DECIMAL],
            'leverage' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'mode' => ['nullable', Rule::in(['paper', 'live'])],
            'exchange_account_id' => ['nullable', 'integer', Rule::exists('exchange_accounts', 'id')->where('user_id', $user->id)],
            'broker_account_id' => ['nullable', 'integer', Rule::exists('broker_accounts', 'id')->where('user_id', $user->id)],
        ];
    }

    /** @return list<string> */
    public function marketTypes(): array
    {
        return match ($this->route('market')) {
            'crypto' => [TradingSymbol::SPOT, TradingSymbol::FUTURES],
            'forex' => [TradingSymbol::FOREX, TradingSymbol::CFD],
            default => TradingSymbol::MARKET_TYPES,
        };
    }
}
