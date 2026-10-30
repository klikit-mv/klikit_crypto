<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;

/**
 * Payment provider integration (Stripe, crypto processors, bank transfer ...).
 * Providers confirm payments via webhooks or admin action, then call PaymentService::complete().
 */
interface PaymentGateway
{
    public function code(): string;

    /** Begin a payment for the invoice. Returns the pending payment with any provider reference. */
    public function initiate(Invoice $invoice): Payment;

    /** Instructions or redirect URL to show the user. */
    public function instructions(Payment $payment): array;
}
