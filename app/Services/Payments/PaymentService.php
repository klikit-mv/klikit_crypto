<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Access\AuditLogger;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
        PaymentGateway ...$gateways,
    ) {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->code()] = $gateway;
        }
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->gateways);
    }

    public function gateway(string $code): PaymentGateway
    {
        return $this->gateways[$code] ?? throw new InvalidArgumentException("Unknown payment provider [$code].");
    }

    public function initiate(Invoice $invoice, string $provider): Payment
    {
        $existing = $invoice->payments()->where('provider', $provider)->where('status', 'pending')->first();

        return $existing ?? $this->gateway($provider)->initiate($invoice);
    }

    /** Confirm a payment (from a verified webhook or an administrator). Idempotent. */
    public function complete(Payment $payment, string $reference, ?User $confirmedBy = null, array $payload = []): Payment
    {
        return DB::transaction(function () use ($payment, $reference, $confirmedBy, $payload) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'completed') {
                return $payment;
            }

            $payment->update(['status' => 'completed', 'completed_at' => now()]);
            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'reference' => $reference,
                'type' => 'capture',
                'status' => 'completed',
                'payload' => $payload ?: null,
            ]);

            if ($payment->invoice) {
                $this->subscriptions->markInvoicePaid($payment->invoice);
            }
            $this->audit->log('payment.completed', $confirmedBy, $payment, ['reference' => $reference]);

            return $payment;
        });
    }

    public function fail(Payment $payment, string $reason, ?User $actor = null): void
    {
        $payment->update(['status' => 'failed']);
        PaymentTransaction::create(['payment_id' => $payment->id, 'reference' => $payment->provider_reference ?? '-', 'type' => 'capture', 'status' => 'failed', 'payload' => ['reason' => $reason]]);
        $this->audit->log('payment.failed', $actor, $payment, ['reason' => $reason]);
    }
}
