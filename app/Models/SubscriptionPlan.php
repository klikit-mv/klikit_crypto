<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price',
        'currency',
        'billing_period',
        'is_active',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function periodInterval(): \DateInterval
    {
        return match ($this->billing_period) {
            'quarterly' => new \DateInterval('P3M'),
            'yearly' => new \DateInterval('P1Y'),
            default => new \DateInterval('P1M'),
        };
    }

    public function featureValue(string $key): ?string
    {
        return $this->features->firstWhere('feature_key', $key)?->feature_value;
    }
}
