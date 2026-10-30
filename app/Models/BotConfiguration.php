<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotConfiguration extends Model
{
    protected $fillable = [
        'bot_id',
        'parameters',
        'risk_settings',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'risk_settings' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class, 'bot_id');
    }
}
