<?php

namespace App\Modules\Subscriptions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::active()->with('features')->orderBy('sort_order')->get();

        return response()->json(['data' => $plans->map(fn (SubscriptionPlan $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
            'price' => $p->price,
            'currency' => $p->currency,
            'billing_period' => $p->billing_period,
            'is_featured' => $p->is_featured,
            'features' => $p->features->pluck('feature_value', 'feature_key'),
        ])]);
    }

    public function current(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $current = $subscriptions->current($request->user());

        return response()->json([
            'subscription' => $current ? [
                'id' => $current->id,
                'plan' => $current->plan->only(['id', 'slug', 'name']),
                'status' => $current->status,
                'starts_at' => $current->starts_at,
                'expires_at' => $current->expires_at,
                'auto_renew' => $current->auto_renew,
            ] : null,
            'entitlements' => $subscriptions->entitlements($request->user()),
        ]);
    }

    public function checkout(Request $request, SubscriptionService $subscriptions, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
            'coupon' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', Rule::in($payments->providers())],
        ]);

        $invoice = $subscriptions->checkout($request->user(), SubscriptionPlan::findOrFail($data['plan_id']), $data['coupon'] ?? null);
        $payment = $invoice->status === 'paid' ? null : $payments->initiate($invoice, $data['provider'] ?? 'manual');

        return response()->json([
            'invoice' => $invoice->only(['id', 'number', 'description', 'subtotal', 'discount', 'total', 'currency', 'status', 'due_at']),
            'payment' => $payment ? [
                'id' => $payment->id,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'instructions' => $payments->gateway($payment->provider)->instructions($payment),
            ] : null,
        ], 201);
    }

    public function cancel(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $current = $subscriptions->current($request->user());
        abort_if($current === null, 404, 'No active subscription.');
        $subscriptions->cancel($current);

        return response()->json(['message' => 'Auto-renewal cancelled. Access continues until '.$current->expires_at->toDateString().'.']);
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json($request->user()->invoices()->latest()->paginate(25));
    }
}
