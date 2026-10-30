<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalLog extends Model
{
    protected $fillable = [
        'signal_id',
        'channel_id',
        'raw_message',
        'parsed_data',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
        ];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(TelegramSignal::class, 'signal_id');
    }
}
