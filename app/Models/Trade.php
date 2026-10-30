<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'position_id',
        'symbol_id',
        'mode',
        'side',
        'quantity',
        'entry_price',
        'exit_price',
        'fee',
        'pnl',
        'pnl_currency',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:18',
            'entry_price' => 'decimal:18',
            'exit_price' => 'decimal:18',
            'fee' => 'decimal:18',
            'pnl' => 'decimal:18',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }
}
