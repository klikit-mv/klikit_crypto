<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeAndRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_test']);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
    }

    private function signed(array $event, string $secret = 'whsec_test'): array
    {
        $payload = json_encode($event);
        $t = time();

        return [$payload, ['Stripe-Signature' => "t=$t,v1=".hash_hmac('sha256', "$t.$payload", $secret), 'Content-Type' => 'application/json']];
    }

    private function completedEvent(int $amount = 4900): array
    {
        return ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => $amount, 'currency' => 'usd', 'payment_intent' => 'pi_1',
        ]]];
    }

    public function test_card_checkout_redirects_to_stripe_and_webhook_activates_plan(): void
    {
        $user = $this->trader();
        $pro = SubscriptionPlan::where('slug', 'pro')->first();

        $this->actingAs($user)->post('/subscription/checkout', ['plan_id' => $pro->id, 'provider' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn ($r) => $r['line_items'][0]['price_data']['unit_amount'] === 4900 && $r->hasHeader('Idempotency-Key'));

        [$payload, $headers] = $this->signed($this->completedEvent());
        $this->call('POST', '/webhooks/stripe', [], [], [], $this->server($headers), $payload)->assertOk();
        $this->call('POST', '/webhooks/stripe', [], [], [], $this->server($headers), $payload)->assertOk(); // retries are harmless

        $this->assertSame('completed', Payment::sole()->status);
        $this->assertSame('pro', app(SubscriptionService::class)->current($user)->plan->slug);
    }

    public function test_webhook_rejects_bad_signatures_and_wrong_amounts(): void
    {
        $user = $this->trader();
        $this->actingAs($user)->post('/subscription/checkout', ['plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id'), 'provider' => 'stripe']);

        [$payload, $headers] = $this->signed($this->completedEvent(), 'whsec_wrong');
        $this->call('POST', '/webhooks/stripe', [], [], [], $this->server($headers), $payload)->assertStatus(400);

        [$payload, $headers] = $this->signed($this->completedEvent(100));
        $this->call('POST', '/webhooks/stripe', [], [], [], $this->server($headers), $payload)->assertOk();

        $this->assertSame('pending', Payment::sole()->status);
        $this->assertNull(app(SubscriptionService::class)->current($user));
    }

    public function test_renewal_invoice_is_issued_once_and_payment_extends_period(): void
    {
        $user = $this->trader();
        $service = app(SubscriptionService::class);
        $service->markInvoicePaid($service->checkout($user, SubscriptionPlan::where('slug', 'starter')->first()));
        $expiry = $service->current($user)->expires_at;

        $this->travelTo($expiry->copy()->subDays(2));
        $this->artisan('subscriptions:renew')->expectsOutput('1 renewal invoices issued.');
        $this->artisan('subscriptions:renew')->expectsOutput('0 renewal invoices issued.');

        $renewal = Invoice::where('status', 'open')->sole();
        $this->assertSame('renewal_invoice', $user->notifications()->first()->data['type']);
        $this->actingAs($user)->get("/subscription/invoices/{$renewal->id}")->assertOk()->assertSee('quoting reference');

        $service->markInvoicePaid($renewal);
        $current = $service->current($user);
        $this->assertTrue($current->expires_at->greaterThan($expiry->copy()->addDays(27)));
        $this->assertNull($current->renewal_invoiced_at);
        $this->assertSame(1, Subscription::where('status', 'active')->count());
    }

    private function server(array $headers): array
    {
        return collect($headers)->mapWithKeys(fn ($v, $k) => [$k === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
    }
}
