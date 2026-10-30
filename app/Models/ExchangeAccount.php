<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeAccount extends Model
{
    protected $fillable = [
        'user_id',
        'exchange_id',
        'label',
        'encrypted_credentials',
        'permissions_scope',
        'status',
        'last_verified_at',
    ];

    protected $hidden = ['encrypted_credentials'];

    protected function casts(): array
    {
        return [
            'encrypted_credentials' => 'encrypted:array',
            'last_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    /** Credentials are decrypted only inside adapters; never serialise them. */
    public function maskedKey(): string
    {
        $key = (string) ($this->encrypted_credentials['api_key'] ?? $this->encrypted_credentials['login'] ?? '');

        return $key === '' ? '' : str_repeat('*', max(0, strlen($key) - 4)).substr($key, -4);
    }
}
