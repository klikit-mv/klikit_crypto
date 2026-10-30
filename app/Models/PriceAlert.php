<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAlert extends Model
{
    public const CONDITIONS = ['price_above', 'price_below', 'rsi_above', 'rsi_below'];

    protected $fillable = [
        'user_id',
        'symbol_id',
        'condition',
        'target',
        'interval',
        'note',
        'status',
        'triggered_value',
        'triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'decimal:18',
            'triggered_value' => 'decimal:18',
            'triggered_at' => 'datetime',
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
}
