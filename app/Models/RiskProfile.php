<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskProfile extends Model
{
    protected $fillable = [
        'user_id',
        'max_amount_per_trade',
        'max_daily_loss',
        'max_open_positions',
        'max_leverage',
        'require_stop_loss',
        'max_total_exposure',
        'trading_enabled',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'max_amount_per_trade' => 'decimal:8',
            'max_daily_loss' => 'decimal:8',
            'max_leverage' => 'decimal:2',
            'max_total_exposure' => 'decimal:8',
            'require_stop_loss' => 'boolean',
            'trading_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
