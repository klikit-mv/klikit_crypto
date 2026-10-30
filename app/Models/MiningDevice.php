<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiningDevice extends Model
{
    protected $fillable = [
        'user_id',
        'pool_id',
        'name',
        'device_type',
        'model',
        'power_watts',
        'electricity_rate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'electricity_rate' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(MiningPool::class, 'pool_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(MiningWorker::class, 'device_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(MiningExpense::class, 'device_id');
    }
}
