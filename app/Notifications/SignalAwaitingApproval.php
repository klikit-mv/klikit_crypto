<?php

namespace App\Notifications;

use App\Models\TelegramSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SignalAwaitingApproval extends Notification
{
    use Queueable;

    public function __construct(public readonly TelegramSignal $signal) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'signal',
            'signal_id' => $this->signal->id,
            'message' => strtoupper($this->signal->signal_type)." {$this->signal->symbol} signal awaiting your approval",
        ];
    }
}
