<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code',
        'discount_type',
        'discount',
        'max_redemptions',
        'times_redeemed',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:8',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isRedeemable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_redemptions === null || $this->times_redeemed < $this->max_redemptions);
    }
}
