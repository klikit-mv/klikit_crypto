<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trade proposed by a bot; executed directly (fully automatic) or after user approval. */
class BotSignal extends Model
{
    protected $fillable = [
        'bot_id',
        'side',
        'type',
        'quantity',
        'price',
        'reason',
        'status',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:18',
            'price' => 'decimal:18',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class, 'bot_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
