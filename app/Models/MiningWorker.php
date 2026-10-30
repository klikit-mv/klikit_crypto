<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiningWorker extends Model
{
    protected $fillable = [
        'device_id',
        'worker_name',
        'status',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MiningDevice::class, 'device_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(MiningStat::class, 'worker_id');
    }
}
