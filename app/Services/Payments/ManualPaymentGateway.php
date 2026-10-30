<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;

/**
 * Bank transfer / off-platform payment confirmed by an administrator.
 */
class ManualPaymentGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'manual';
    }

    public function initiate(Invoice $invoice): Payment
    {
        return Payment::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'provider' => $this->code(),
            'provider_reference' => $invoice->number,
            'status' => 'pending',
        ]);
    }

    public function instructions(Payment $payment): array
    {
        return [
            'type' => 'bank_transfer',
            'reference' => $payment->provider_reference,
            'message' => "Transfer {$payment->amount} {$payment->currency} quoting reference {$payment->provider_reference}. Your plan activates once an administrator confirms receipt.",
        ];
    }
}
