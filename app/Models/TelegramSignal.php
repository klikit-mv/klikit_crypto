<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramSignal extends Model
{
    protected $fillable = [
        'channel_id',
        'symbol_id',
        'symbol',
        'signal_type',
        'entry_price',
        'entry_price_max',
        'stop_loss',
        'status',
        'rejection_reason',
        'received_at',
        'message_id',
        'market_type',
        'leverage',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'leverage' => 'decimal:2',
            'expires_at' => 'datetime',
            'entry_price' => 'decimal:18',
            'entry_price_max' => 'decimal:18',
            'stop_loss' => 'decimal:18',
            'received_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }

    public function tradingSymbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SignalTarget::class, 'signal_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SignalExecution::class, 'signal_id');
    }
}
