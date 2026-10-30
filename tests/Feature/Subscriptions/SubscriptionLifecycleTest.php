<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_plans_are_public(): void
    {
        $plans = $this->getJson('/api/v1/subscriptions/plans')->assertOk()->assertJsonCount(3, 'data')->json('data');

        $this->assertSame('pro', $plans[1]['slug']);
        $this->assertSame('true', $plans[1]['features']['bots.automated']);
    }

    public function test_checkout_then_admin_confirmation_activates_plan_and_entitlements(): void
    {
        $user = $this->trader();
        $pro = SubscriptionPlan::where('slug', 'pro')->first();
        $service = app(SubscriptionService::class);
        $this->assertFalse($service->hasFeature($user, 'bots.automated'));

        $response = $this->actingAs($user)->postJson('/api/v1/subscriptions/checkout', ['plan_id' => $pro->id], $this->idempotencyHeaders())
            ->assertCreated()
            ->assertJsonPath('invoice.status', 'open')
            ->assertJsonPath('payment.provider', 'manual');

        $admin = $this->staff(Role::ADMIN);
        $payment = Payment::findOrFail($response->json('payment.id'));
        $this->actingAs($admin)->post("/admin/payments/{$payment->id}/confirm", ['reference' => 'BANK-123'])->assertRedirect();

        $current = $this->actingAs($user)->getJson('/api/v1/subscriptions/current')->assertJsonPath('subscription.plan.slug', 'pro');
        $this->assertSame('true', $current->json('entitlements')['bots.automated']);
        $this->assertTrue($user->fresh()->hasRole(Role::PREMIUM_USER));
        $this->assertSame('paid', $payment->invoice->fresh()->status);
    }

    public function test_confirming_twice_does_not_extend_twice(): void
    {
        $user = $this->trader();
        $invoice = app(SubscriptionService::class)->checkout($user, SubscriptionPlan::where('slug', 'starter')->first());
        $service = app(SubscriptionService::class);

        $service->markInvoicePaid($invoice);
        $expires = $service->current($user)->expires_at;
        $service->markInvoicePaid($invoice);

        $this->assertEquals($expires, $service->current($user)->expires_at);
    }

    public function test_renewing_the_same_plan_extends_expiry(): void
    {
        $user = $this->trader();
        $plan = SubscriptionPlan::where('slug', 'starter')->first();
        $service = app(SubscriptionService::class);

        $service->markInvoicePaid($service->checkout($user, $plan));
        $firstExpiry = $service->current($user)->expires_at;
        $service->markInvoicePaid($service->checkout($user, $plan));

        $this->assertTrue($service->current($user)->expires_at->greaterThan($firstExpiry->copy()->addDays(27)));
        $this->assertSame(1, Subscription::where('user_id', $user->id)->where('status', 'active')->count());
    }

    public function test_coupon_discount_and_full_discount_activates_immediately(): void
    {
        $user = $this->trader();
        Coupon::create(['code' => 'HALF', 'discount_type' => 'percent', 'discount' => 50]);
        Coupon::create(['code' => 'FREE', 'discount_type' => 'percent', 'discount' => 100, 'max_redemptions' => 1]);
        $pro = SubscriptionPlan::where('slug', 'pro')->first();

        $this->actingAs($user)->postJson('/api/v1/subscriptions/checkout', ['plan_id' => $pro->id, 'coupon' => 'HALF'], $this->idempotencyHeaders())
            ->assertJsonPath('invoice.total', '24.50000000');

        $this->actingAs($user)->postJson('/api/v1/subscriptions/checkout', ['plan_id' => $pro->id, 'coupon' => 'FREE'], $this->idempotencyHeaders())
            ->assertJsonPath('invoice.status', 'paid')->assertJsonPath('payment', null);
        $this->actingAs($this->trader())->postJson('/api/v1/subscriptions/checkout', ['plan_id' => $pro->id, 'coupon' => 'FREE'], $this->idempotencyHeaders())
            ->assertJsonValidationErrors('coupon');
    }

    public function test_lapsed_subscriptions_expire_and_entitlements_revert(): void
    {
        $user = $this->trader();
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', 'pro')->first()));

        $this->travel(32)->days();
        Artisan::call('subscriptions:expire');

        $this->assertSame('expired', Subscription::where('user_id', $user->id)->latest('id')->first()->status);
        $this->assertFalse($user->fresh()->hasRole(Role::PREMIUM_USER));
        $this->assertFalse(app(SubscriptionService::class)->hasFeature($user->fresh(), 'bots.automated'));
    }

    public function test_admin_can_edit_plan_prices_and_entitlements(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $plan = SubscriptionPlan::where('slug', 'starter')->first();

        $this->actingAs($admin)->put("/admin/plans/{$plan->id}", [
            'slug' => 'starter', 'name' => 'Starter', 'price' => '29', 'currency' => 'USD', 'billing_period' => 'monthly', 'is_active' => 1,
            'features' => ['trading.manual' => 'true', 'signals.daily_limit' => '10'],
        ])->assertRedirect('/admin/plans');

        $plan->refresh()->load('features');
        $this->assertSame('29.00000000', $plan->price);
        $this->assertSame('10', $plan->featureValue('signals.daily_limit'));
        $this->assertNull($plan->featureValue('trading.paper'));
    }
}
