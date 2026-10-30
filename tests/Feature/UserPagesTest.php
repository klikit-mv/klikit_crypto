<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
    }

    public function test_web_registration_logs_in_and_shows_dashboard(): void
    {
        $this->post('/register', ['name' => 'Web', 'email' => 'web@example.com', 'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9'])
            ->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk()->assertSee('Total portfolio')->assertSee('$20,000.00', false); // 10,000 USDT + 10,000 USD paper funds
    }

    public function test_user_pages_render(): void
    {
        $user = $this->trader();
        foreach (['/dashboard', '/trading', '/trading/risk', '/wallet', '/subscription'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->actingAs($user)->post('/subscription/checkout', ['plan_id' => SubscriptionPlan::first()->id])->assertRedirect();
        $this->actingAs($user)->get('/subscription/invoices/'.$user->invoices()->first()->id)->assertOk()->assertSee('quoting reference');
    }

    public function test_users_cannot_view_other_users_invoices(): void
    {
        $owner = $this->trader();
        $this->actingAs($owner)->post('/subscription/checkout', ['plan_id' => SubscriptionPlan::first()->id]);

        $this->actingAs($this->trader())->get('/subscription/invoices/'.$owner->invoices()->first()->id)->assertNotFound();
    }
}
