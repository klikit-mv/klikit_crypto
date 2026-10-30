<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalExecution extends Model
{
    protected $fillable = [
        'signal_id',
        'user_id',
        'order_id',
        'status',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(TelegramSignal::class, 'signal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
