<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderExecution extends Model
{
    protected $fillable = [
        'order_id',
        'execution_price',
        'quantity',
        'fee',
        'fee_currency',
        'external_trade_id',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'execution_price' => 'decimal:18',
            'quantity' => 'decimal:18',
            'fee' => 'decimal:18',
            'executed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
