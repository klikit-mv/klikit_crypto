<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiningStat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'worker_id',
        'hashrate',
        'temperature',
        'accepted_shares',
        'rejected_shares',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'hashrate' => 'decimal:4',
            'temperature' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(MiningWorker::class, 'worker_id');
    }
}
