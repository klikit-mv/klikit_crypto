<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiningPool extends Model
{
    protected $fillable = [
        'name',
        'endpoint',
        'algorithm',
        'coin',
        'fee_percent',
        'is_active',
        'api_type',
        'api_url',
    ];

    protected function casts(): array
    {
        return [
            'fee_percent' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MiningPoolAccount::class, 'pool_id');
    }
}
