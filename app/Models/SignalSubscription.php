<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'channel_id',
        'execution_mode',
        'amount_per_signal',
        'max_leverage',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount_per_signal' => 'decimal:8',
            'max_leverage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }
}
