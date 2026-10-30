<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotBacktest extends Model
{
    protected $fillable = [
        'strategy_id',
        'user_id',
        'symbol_id',
        'parameters',
        'period_start',
        'period_end',
        'status',
        'results',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'results' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(BotStrategy::class, 'strategy_id');
    }
}
