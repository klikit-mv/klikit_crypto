<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiningPoolAccount extends Model
{
    protected $fillable = [
        'user_id',
        'pool_id',
        'account_name',
        'encrypted_api_key',
        'last_synced_at',
    ];

    protected $hidden = ['encrypted_api_key'];

    protected function casts(): array
    {
        return [
            'encrypted_api_key' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(MiningPool::class, 'pool_id');
    }
}
