<?php

namespace Tests\Feature\Access;

use App\Models\Exchange;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_regular_users_cannot_enter_the_admin_area(): void
    {
        $this->actingAs($this->trader())->get('/admin')->assertForbidden();
    }

    public function test_admin_pages_render_for_super_admin(): void
    {
        $admin = $this->staff(Role::SUPER_ADMIN);
        $target = $this->trader();

        foreach (['/admin', '/admin/users', "/admin/users/{$target->id}", '/admin/plans', '/admin/plans/create', '/admin/payments',
            '/admin/wallet', '/admin/integrations', '/admin/trading', '/admin/trading/orders', '/admin/audit-logs',
            '/admin/failed-jobs', '/admin/announcements'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_permissions_not_role_names_decide_access(): void
    {
        $support = $this->staff(Role::SUPPORT);

        $this->actingAs($support)->get('/admin/users')->assertOk();
        $this->actingAs($support)->get('/admin/plans')->assertForbidden();
        $this->actingAs($support)->get('/admin/trading')->assertForbidden();
        $this->actingAs($support)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_admin_can_suspend_user_and_action_is_audited(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $user = $this->trader();

        $this->actingAs($admin)->put("/admin/users/{$user->id}/status", ['status' => 'suspended'])->assertRedirect();

        $this->assertSame(User::SUSPENDED, $user->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_status', 'user_id' => $admin->id, 'subject_id' => $user->id]);
    }

    public function test_only_super_admin_can_grant_super_admin(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $user = $this->trader();
        $superId = Role::where('name', Role::SUPER_ADMIN)->value('id');

        $this->actingAs($admin)->put("/admin/users/{$user->id}/roles", ['roles' => [$superId]])->assertForbidden();
        $this->assertFalse($user->fresh()->hasRole(Role::SUPER_ADMIN));
    }

    public function test_staff_never_see_exchange_secrets(): void
    {
        $admin = $this->staff(Role::SUPER_ADMIN);
        $user = $this->trader();
        $exchange = Exchange::first();
        $exchange->update(['status' => 'enabled']);

        $this->actingAs($user)->postJson('/api/v1/accounts/exchanges', [
            'exchange_id' => $exchange->id, 'label' => 'Main', 'api_key' => 'AKIA-PUBLIC-1234', 'api_secret' => 'TOP-SECRET-VALUE',
        ])->assertCreated()->assertJsonPath('data.key', '************1234')->assertJsonMissing(['api_secret' => 'TOP-SECRET-VALUE']);

        $this->assertStringNotContainsString('TOP-SECRET-VALUE', (string) \DB::table('exchange_accounts')->value('encrypted_credentials'));
        $this->actingAs($admin)->get("/admin/users/{$user->id}")->assertOk()->assertDontSee('TOP-SECRET-VALUE')->assertDontSee('AKIA-PUBLIC-1234');
    }
}
