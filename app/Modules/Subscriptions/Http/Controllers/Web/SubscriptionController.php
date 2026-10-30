<?php

namespace App\Modules\Subscriptions\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions): View
    {
        return view('user.subscription', [
            'plans' => SubscriptionPlan::active()->with('features')->orderBy('sort_order')->get(),
            'current' => $subscriptions->current($request->user()),
            'invoices' => $request->user()->invoices()->with('payments')->latest()->limit(20)->get(),
            'featureLabels' => config('plans.features'),
            'providers' => app(PaymentService::class)->providers(),
        ]);
    }

    public function checkout(Request $request, SubscriptionService $subscriptions, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
            'coupon' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', Rule::in($payments->providers())],
        ]);
        $invoice = $subscriptions->checkout($request->user(), SubscriptionPlan::findOrFail($data['plan_id']), $data['coupon'] ?? null);
        if ($invoice->status !== 'paid') {
            $payment = $payments->initiate($invoice, $data['provider'] ?? 'manual');
            $url = $payments->gateway($payment->provider)->instructions($payment)['url'] ?? null;
            if ($url) {
                return redirect()->away($url);
            }
        }

        return redirect()->route('subscription.invoice', $invoice);
    }

    public function invoice(Request $request, Invoice $invoice, PaymentService $payments): View
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);
        $payment = $invoice->payments()->latest('id')->first();
        if ($invoice->status === 'open' && $payment === null) {
            $payment = $payments->initiate($invoice, 'manual'); // e.g. renewal invoices
        }

        return view('user.invoice', [
            'invoice' => $invoice,
            'payment' => $payment,
            'instructions' => $payment ? $payments->gateway($payment->provider)->instructions($payment) : null,
        ]);
    }

    public function cancel(Request $request, SubscriptionService $subscriptions): RedirectResponse
    {
        $current = $subscriptions->current($request->user());
        abort_if($current === null, 404);
        $subscriptions->cancel($current);

        return back()->with('status', 'Auto-renewal cancelled.');
    }
}
