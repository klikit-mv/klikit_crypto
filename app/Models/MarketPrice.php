<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketPrice extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'symbol_id',
        'source',
        'bid',
        'ask',
        'price',
        'change_24h',
        'volume_24h',
        'timestamp',
    ];

    protected function casts(): array
    {
        return [
            'bid' => 'decimal:18',
            'ask' => 'decimal:18',
            'price' => 'decimal:18',
            'change_24h' => 'decimal:4',
            'volume_24h' => 'decimal:18',
            'timestamp' => 'datetime',
        ];
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }
}
