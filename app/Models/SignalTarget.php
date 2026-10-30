<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalTarget extends Model
{
    protected $fillable = [
        'signal_id',
        'target_number',
        'target_price',
        'hit_at',
    ];

    protected function casts(): array
    {
        return [
            'target_price' => 'decimal:18',
            'hit_at' => 'datetime',
        ];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(TelegramSignal::class, 'signal_id');
    }
}
