<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('plans.defaults') as $slug => $definition) {
            // Never overwrite prices an admin has already changed.
            $plan = SubscriptionPlan::firstOrCreate(['slug' => $slug], [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'price' => $definition['price'],
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'is_featured' => $definition['is_featured'],
                'sort_order' => $definition['sort_order'],
            ]);

            if ($plan->wasRecentlyCreated) {
                foreach ($definition['features'] as $key => $value) {
                    $plan->features()->create(['feature_key' => $key, 'feature_value' => $value]);
                }
            }
        }
    }
}
