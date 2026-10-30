<?php

namespace App\Notifications;

use App\Models\BotSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BotSignalAwaitingApproval extends Notification
{
    use Queueable;

    public function __construct(public readonly BotSignal $signal) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bot_signal',
            'bot_signal_id' => $this->signal->id,
            'message' => "Bot {$this->signal->bot->name} proposes ".strtoupper($this->signal->side).": {$this->signal->reason}",
        ];
    }
}
