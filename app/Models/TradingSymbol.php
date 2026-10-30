<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradingSymbol extends Model
{
    public const SPOT = 'SPOT';

    public const FUTURES = 'FUTURES';

    public const FOREX = 'FOREX';

    public const CFD = 'CFD';

    public const MARKET_TYPES = [self::SPOT, self::FUTURES, self::FOREX, self::CFD];

    protected $fillable = [
        'symbol',
        'market_type',
        'base_currency',
        'quote_currency',
        'price_precision',
        'quantity_precision',
        'min_quantity',
        'max_leverage',
        'contract_size',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:18',
            'max_leverage' => 'decimal:2',
            'contract_size' => 'decimal:18',
            'is_active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(MarketPrice::class, 'symbol_id');
    }

    public function latestPrice(): HasOne
    {
        return $this->hasOne(MarketPrice::class, 'symbol_id')->latestOfMany('timestamp');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Spot instruments settle by delivering the base asset; everything else is a margined position. */
    public function isSpot(): bool
    {
        return $this->market_type === self::SPOT;
    }

    /** Forex and CFD instruments are routed to broker adapters, crypto instruments to exchange adapters. */
    public function usesBroker(): bool
    {
        return in_array($this->market_type, [self::FOREX, self::CFD], true);
    }
}
