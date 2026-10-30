<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MiningPool extends Model
{
    protected $fillable = [
        'name',
        'endpoint',
        'algorithm',
        'coin',
        'fee_percent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee_percent' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
