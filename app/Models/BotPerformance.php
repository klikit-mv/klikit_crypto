<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotPerformance extends Model
{
    protected $table = 'bot_performance';

    protected $fillable = [
        'bot_id',
        'period_date',
        'profit',
        'drawdown',
        'win_rate',
        'trades_count',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'profit' => 'decimal:18',
            'drawdown' => 'decimal:4',
            'win_rate' => 'decimal:4',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class, 'bot_id');
    }
}
