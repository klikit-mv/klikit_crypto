<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotStrategy extends Model
{
    public const TYPES = ['grid', 'dca', 'scalping', 'trend', 'rsi', 'macd', 'telegram_signal', 'manual'];

    protected $fillable = [
        'name',
        'strategy_type',
        'description',
        'configuration',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function bots(): HasMany
    {
        return $this->hasMany(TradingBot::class, 'strategy_id');
    }
}
