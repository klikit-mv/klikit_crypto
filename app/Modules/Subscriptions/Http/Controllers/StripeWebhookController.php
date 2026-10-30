<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePaymentGateway $stripe, PaymentService $payments): JsonResponse
    {
        abort_unless($stripe->verifySignature($request->getContent(), (string) $request->header('Stripe-Signature')), 400, 'Invalid signature.');

        $event = $request->json()->all();
        $session = $event['data']['object'] ?? [];

        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            && ($session['payment_status'] ?? null) === 'paid') {
            $payment = Payment::where('provider', 'stripe')->where('provider_reference', $session['id'] ?? '')->first();
            if ($payment && $this->amountMatches($stripe, $payment, $session)) {
                $payments->complete($payment, (string) ($session['payment_intent'] ?? $session['id']), null, ['event' => $event['id'] ?? null]);
            }
        }

        if (($event['type'] ?? '') === 'checkout.session.expired') {
            Payment::where('provider', 'stripe')->where('provider_reference', $session['id'] ?? '')->where('status', 'pending')->update(['status' => 'failed']);
        }

        return response()->json(['received' => true]);
    }

    private function amountMatches(StripePaymentGateway $stripe, Payment $payment, array $session): bool
    {
        return (int) ($session['amount_total'] ?? -1) === $stripe->minorUnits($payment->amount, $payment->currency)
            && strtoupper((string) ($session['currency'] ?? '')) === strtoupper($payment->currency);
    }
}
