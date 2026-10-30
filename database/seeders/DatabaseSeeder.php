<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\UserRegistrationService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SubscriptionPlanSeeder::class,
            MarketSeeder::class,
            BotStrategySeeder::class,
            MiningPoolSeeder::class,
        ]);

        // Demo accounts for local development only. In production create admins with
        // `php artisan admin:create`.
        if (app()->environment('local')) {
            $registration = app(UserRegistrationService::class);

            if (! User::where('email', 'admin@example.com')->exists()) {
                $admin = $registration->register(['name' => 'Platform Admin', 'email' => 'admin@example.com', 'password' => 'password123']);
                $admin->assignRole(Role::SUPER_ADMIN);
            }
            if (! User::where('email', 'trader@example.com')->exists()) {
                $registration->register(['name' => 'Demo Trader', 'email' => 'trader@example.com', 'password' => 'password123']);
            }
        }
    }
}
