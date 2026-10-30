<?php

namespace App\Services\Subscriptions;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Access\AuditLogger;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    /** @var array<int, array<string, string>> per-request entitlement cache keyed by user id */
    private array $entitlements = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, string> feature key => value */
    public function entitlements(User $user): array
    {
        if (isset($this->entitlements[$user->id])) {
            return $this->entitlements[$user->id];
        }

        $features = config('plans.free', []);
        $subscription = $this->current($user);
        if ($subscription) {
            foreach ($subscription->plan->features as $feature) {
                $features[$feature->feature_key] = $feature->feature_value;
            }
        }

        return $this->entitlements[$user->id] = $features;
    }

    public function hasFeature(User $user, string $key): bool
    {
        $value = $this->entitlements($user)[$key] ?? 'false';

        return ! in_array(strtolower($value), ['false', '0', ''], true);
    }

    /** Numeric limit for a feature; -1 means unlimited. */
    public function limit(User $user, string $key): int
    {
        return (int) ($this->entitlements($user)[$key] ?? 0);
    }

    public function current(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)->currentlyActive()->with('plan.features')->latest('expires_at')->first();
    }

    /**
     * Start checkout: creates a pending subscription and an open invoice.
     */
    public function checkout(User $user, SubscriptionPlan $plan, ?string $couponCode = null): Invoice
    {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'This plan is not available.']);
        }

        return DB::transaction(function () use ($user, $plan, $couponCode) {
            $coupon = null;
            $discount = Decimal::of('0');
            if ($couponCode) {
                $coupon = Coupon::where('code', $couponCode)->lockForUpdate()->first();
                if (! $coupon?->isRedeemable()) {
                    throw ValidationException::withMessages(['coupon' => 'This coupon is invalid or has expired.']);
                }
                $discount = $coupon->discount_type === 'percent'
                    ? Decimal::div(Decimal::mul($plan->price, $coupon->discount), 100)
                    : Decimal::of($coupon->discount);
                $discount = Decimal::min($discount, $plan->price);
            }

            // Only one pending checkout per user at a time.
            Subscription::where('user_id', $user->id)->where('status', Subscription::PENDING)->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now()]);
            Invoice::where('user_id', $user->id)->where('status', 'open')->update(['status' => 'void']);

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => Subscription::PENDING,
            ]);

            $total = Decimal::of($plan->price)->minus($discount)->toScale(8, RoundingMode::HALF_UP);

            $invoice = Invoice::create([
                'number' => 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'coupon_id' => $coupon?->id,
                'description' => "{$plan->name} plan ({$plan->billing_period})",
                'subtotal' => Decimal::str($plan->price, 8),
                'discount' => Decimal::str($discount, 8),
                'total' => (string) $total,
                'currency' => $plan->currency,
                'status' => 'open',
                'due_at' => now()->addDays(7),
            ]);

            if ($total->isZero()) {
                $this->markInvoicePaid($invoice);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Called once payment for an invoice is confirmed. Activates (or extends) the subscription.
     */
    public function markInvoicePaid(Invoice $invoice): Subscription
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $subscription = $invoice->subscription()->with('plan')->firstOrFail();
            if ($invoice->status === 'paid') {
                return $subscription;
            }

            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            if ($invoice->coupon_id) {
                Coupon::whereKey($invoice->coupon_id)->increment('times_redeemed');
            }

            $user = $invoice->user;
            $active = $this->current($user);

            if ($active && $active->plan_id === $subscription->plan_id && $active->id !== $subscription->id) {
                // Renewal of the same plan: extend from the current expiry.
                $active->update(['expires_at' => $active->expires_at->copy()->add($subscription->plan->periodInterval())]);
                $subscription->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now()]);
                $subscription = $active;
            } else {
                // New plan or upgrade: replace whatever was active.
                if ($active && $active->id !== $subscription->id) {
                    $active->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now()]);
                }
                $subscription->update([
                    'status' => Subscription::ACTIVE,
                    'starts_at' => now(),
                    'expires_at' => now()->add($subscription->plan->periodInterval()),
                ]);
            }

            $user->assignRole(Role::PREMIUM_USER);
            unset($this->entitlements[$user->id]);
            $this->audit->log('subscription.activated', $user, $subscription, ['invoice' => $invoice->number]);

            return $subscription->refresh();
        });
    }

    public function cancel(Subscription $subscription): void
    {
        // Access continues until the paid period ends; only auto-renewal stops.
        $subscription->update(['auto_renew' => false, 'cancelled_at' => now()]);
        $this->audit->log('subscription.cancelled', $subscription->user, $subscription);
    }

    /** Expire lapsed subscriptions. Returns the number expired. */
    public function expireLapsed(): int
    {
        $count = 0;
        Subscription::where('status', Subscription::ACTIVE)
            ->where('expires_at', '<=', now())
            ->with('user')
            ->chunkById(200, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => Subscription::EXPIRED]);
                    $user = $subscription->user;
                    if (! Subscription::where('user_id', $user->id)->currentlyActive()->exists()) {
                        $user->roles()->detach(Role::where('name', Role::PREMIUM_USER)->value('id'));
                        $user->assignRole(Role::STANDARD_USER);
                    }
                    unset($this->entitlements[$user->id]);
                    $this->audit->log('subscription.expired', null, $subscription);
                    $count++;
                }
            });

        return $count;
    }
}
