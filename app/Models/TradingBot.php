<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingBot extends Model
{
    protected $fillable = [
        'user_id',
        'strategy_id',
        'symbol_id',
        'name',
        'market',
        'mode',
        'execution_mode',
        'status',
        'state',
        'last_run_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(BotStrategy::class, 'strategy_id');
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }

    public function configurations(): HasMany
    {
        return $this->hasMany(BotConfiguration::class, 'bot_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BotRun::class, 'bot_id');
    }

    public function performance(): HasMany
    {
        return $this->hasMany(BotPerformance::class, 'bot_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(BotSignal::class, 'bot_id');
    }

    public function botOrders(): HasMany
    {
        return $this->hasMany(BotOrder::class, 'bot_id');
    }

    public function currentConfiguration(): ?BotConfiguration
    {
        return $this->configurations()->latest('version')->first();
    }
}
