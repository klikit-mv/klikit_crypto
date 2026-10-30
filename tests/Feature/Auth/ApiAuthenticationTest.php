<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_registration_creates_account_with_role_risk_profile_and_paper_funds(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
        ]);

        $response->assertCreated()->assertJsonPath('user.roles.0', 'standard_user')->assertJsonStructure(['token']);

        $user = User::whereEmail('alice@example.com')->first();
        $this->assertNotNull($user->riskProfile);
        $this->assertSame('10000.000000000000000000', Wallet::where(['user_id' => $user->id, 'currency' => 'USDT', 'wallet_type' => 'paper'])->value('balance'));
    }

    public function test_login_returns_token_that_authenticates_requests(): void
    {
        $this->trader(['email' => 'bob@example.com']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'bob@example.com', 'password' => 'secret-password-1'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/profile')->assertOk()->assertJsonPath('user.email', 'bob@example.com');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->trader(['email' => 'bob@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'bob@example.com', 'password' => 'nope'])->assertUnprocessable();
    }

    public function test_suspended_user_cannot_log_in_or_use_existing_token(): void
    {
        $user = $this->trader(['email' => 'eve@example.com']);
        $token = $user->createToken('t')->plainTextToken;
        $user->forceFill(['status' => User::SUSPENDED])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'eve@example.com', 'password' => 'secret-password-1'])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/dashboard/overview')->assertForbidden();
    }

    public function test_status_is_not_mass_assignable(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory', 'email' => 'm@example.com', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9', 'status' => 'closed',
        ])->assertCreated();

        $this->assertSame('active', User::whereEmail('m@example.com')->value('status'));
    }
}
