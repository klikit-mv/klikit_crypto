<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $fillable = [
        'user_id',
        'symbol_id',
        'mode',
        'side',
        'quantity',
        'average_price',
        'leverage',
        'margin',
        'margin_currency',
        'stop_loss',
        'take_profit',
        'realized_pnl',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:18',
            'average_price' => 'decimal:18',
            'leverage' => 'decimal:2',
            'margin' => 'decimal:18',
            'stop_loss' => 'decimal:18',
            'take_profit' => 'decimal:18',
            'realized_pnl' => 'decimal:18',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
