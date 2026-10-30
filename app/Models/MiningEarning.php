<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiningEarning extends Model
{
    /** Calendar dates are stored as plain Y-m-d strings so equality lookups work on every driver. */
    protected $fillable = [
        'user_id',
        'pool_id',
        'amount',
        'currency',
        'date',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
