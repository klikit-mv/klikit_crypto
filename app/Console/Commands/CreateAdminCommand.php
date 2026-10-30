<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Services\Access\UserRegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {email} {--name=Administrator} {--role=super_admin}';

    protected $description = 'Create a staff account (prompts for the password)';

    public function handle(UserRegistrationService $registration): int
    {
        $password = $this->secret('Password (min 12 chars)');
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password, 'role' => $this->option('role')],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
                'role' => ['required', Rule::in(Role::STAFF)],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $registration->register(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password]);
        $user->assignRole($this->option('role'));
        $this->info("Created {$this->option('role')} {$user->email} (#{$user->id}).");

        return self::SUCCESS;
    }
}
