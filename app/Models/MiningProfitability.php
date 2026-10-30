<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiningProfitability extends Model
{
    protected $table = 'mining_profitability';

    protected $fillable = [
        'device_id',
        'date',
        'revenue',
        'expenses',
        'net_profit',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'revenue' => 'decimal:8',
            'expenses' => 'decimal:8',
            'net_profit' => 'decimal:8',
            'date' => 'date',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MiningDevice::class, 'device_id');
    }
}
