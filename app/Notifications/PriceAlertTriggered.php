<?php

namespace App\Notifications;

use App\Models\PriceAlert;
use App\Support\Decimal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceAlertTriggered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PriceAlert $alert) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Alert: {$this->summary()}")->line($this->summary())->line($this->alert->note ?? '');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'price_alert', 'alert_id' => $this->alert->id, 'message' => $this->summary()];
    }

    private function summary(): string
    {
        $label = str_replace('_', ' ', $this->alert->condition);

        return "{$this->alert->symbol->symbol} {$label} ".Decimal::display($this->alert->target).' (now '.Decimal::display($this->alert->triggered_value).')';
    }
}
