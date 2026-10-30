<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBot extends Model
{
    protected $fillable = [
        'user_id',
        'username',
        'bot_token_encrypted',
        'status',
    ];

    protected $hidden = ['bot_token_encrypted'];

    protected function casts(): array
    {
        return [
            'bot_token_encrypted' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
