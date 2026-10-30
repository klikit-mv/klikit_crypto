<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotOrder extends Model
{
    protected $fillable = [
        'bot_id',
        'bot_run_id',
        'order_id',
        'execution_status',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class, 'bot_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
