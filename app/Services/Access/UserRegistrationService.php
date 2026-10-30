<?php

namespace App\Services\Access;

use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\WalletService;
use App\Services\Trading\RiskManagementService;
use Illuminate\Support\Facades\DB;

class UserRegistrationService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly RiskManagementService $risk,
        private readonly AuditLogger $audit,
    ) {}

    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->assignRole(Role::STANDARD_USER);
            $user->profile()->create(['country' => $data['country'] ?? null, 'timezone' => $data['timezone'] ?? 'UTC']);
            $user->settings()->create(['currency' => 'USD']);
            $this->risk->profileFor($user);
            $this->wallets->fundPaperAccount($user);

            return $user;
        });

        $this->audit->log('user.registered', $user, $user);

        return $user;
    }
}
