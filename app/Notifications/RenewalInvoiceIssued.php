<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\Decimal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenewalInvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Renewal invoice {$this->invoice->number}")
            ->line($this->message())
            ->action('View invoice', route('subscription.invoice', $this->invoice));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'renewal_invoice', 'invoice_id' => $this->invoice->id, 'message' => $this->message()];
    }

    private function message(): string
    {
        return "Your {$this->invoice->description} renews soon: ".Decimal::display($this->invoice->total, 2)." {$this->invoice->currency} due by {$this->invoice->due_at->toDateString()}.";
    }
}
