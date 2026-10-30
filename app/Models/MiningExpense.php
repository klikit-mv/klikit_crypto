<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiningExpense extends Model
{
    protected $fillable = [
        'device_id',
        'electricity_cost',
        'pool_fees',
        'other_costs',
        'currency',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'electricity_cost' => 'decimal:8',
            'pool_fees' => 'decimal:8',
            'other_costs' => 'decimal:8',
            'date' => 'date',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MiningDevice::class, 'device_id');
    }
}
