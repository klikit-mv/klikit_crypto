<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Card payments through Stripe Checkout. The invoice is paid on the hosted page; the
 * subscription activates only when the signed `checkout.session.completed` webhook arrives.
 */
class StripePaymentGateway implements PaymentGateway
{
    /** Currencies Stripe expects in whole units rather than cents. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Seconds a webhook signature stays valid. */
    private const SIGNATURE_TOLERANCE = 300;

    public function code(): string
    {
        return 'stripe';
    }

    public static function configured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public function initiate(Invoice $invoice): Payment
    {
        $payment = Payment::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'provider' => $this->code(),
            'status' => 'pending',
        ]);

        $response = Http::asForm()
            ->withToken((string) config('services.stripe.secret'))
            ->withHeaders(['Idempotency-Key' => 'payment-'.$payment->id])
            ->post(rtrim(config('services.stripe.api_url'), '/').'/v1/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $invoice->number,
                'customer_email' => $invoice->user->email,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($invoice->currency),
                        'unit_amount' => $this->minorUnits($invoice->total, $invoice->currency),
                        'product_data' => ['name' => $invoice->description],
                    ],
                ]],
                'metadata' => ['payment_id' => $payment->id, 'invoice' => $invoice->number],
                'success_url' => route('subscription.invoice', $invoice).'?paid=1',
                'cancel_url' => route('subscription.invoice', $invoice),
            ]);

        if ($response->failed()) {
            $payment->update(['status' => 'failed']);
            throw new RuntimeException('Stripe checkout could not be created: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $payment->update(['provider_reference' => $response->json('id')]);
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'reference' => $response->json('id'),
            'type' => 'authorize',
            'status' => 'pending',
            'payload' => ['checkout_url' => $response->json('url')],
        ]);

        return $payment;
    }

    public function instructions(Payment $payment): array
    {
        $url = $payment->transactions()->where('type', 'authorize')->latest('id')->first()?->payload['checkout_url'] ?? null;

        return [
            'type' => 'redirect',
            'url' => $url,
            'message' => 'Pay securely by card on Stripe. Your plan activates as soon as Stripe confirms the payment.',
        ];
    }

    /** Verifies the Stripe-Signature header (t=timestamp, v1=HMAC-SHA256 of "t.payload"). */
    public function verifySignature(string $payload, string $header): bool
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
            $parts[$key][] = $value;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function minorUnits(string $amount, string $currency): int
    {
        $scale = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;

        return (int) (string) Decimal::of($amount)->toScale($scale, RoundingMode::HALF_UP)->withPointMovedRight($scale)->toBigInteger();
    }
}
