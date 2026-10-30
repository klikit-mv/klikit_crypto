<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiningPayout extends Model
{
    protected $fillable = [
        'user_id',
        'pool_id',
        'amount',
        'currency',
        'transaction_hash',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
