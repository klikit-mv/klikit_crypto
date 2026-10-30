<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketCandle extends Model
{
    public const INTERVALS = ['1m', '5m', '15m', '1h', '4h', '1d'];

    public $timestamps = false;

    protected $fillable = [
        'symbol_id',
        'interval',
        'open_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'open_time' => 'datetime',
            'open' => 'decimal:18',
            'high' => 'decimal:18',
            'low' => 'decimal:18',
            'close' => 'decimal:18',
            'volume' => 'decimal:18',
        ];
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }

    /** Interval length in seconds. */
    public static function seconds(string $interval): int
    {
        return match ($interval) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '4h' => 14400,
            default => 86400,
        };
    }
}
