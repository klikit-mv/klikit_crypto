<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exchange extends Model
{
    protected $fillable = [
        'code',
        'name',
        'api_type',
        'status',
        'supports_spot',
        'supports_futures',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'supports_spot' => 'boolean',
            'supports_futures' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ExchangeAccount::class);
    }
}
