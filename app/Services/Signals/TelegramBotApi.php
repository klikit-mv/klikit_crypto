<?php

namespace App\Services\Signals;

use App\Models\TelegramBot;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Minimal Telegram Bot API client. */
class TelegramBotApi
{
    public function getMe(TelegramBot $bot): array
    {
        return $this->call($bot, 'getMe');
    }

    public function setWebhook(TelegramBot $bot, string $url): array
    {
        return $this->call($bot, 'setWebhook', [
            'url' => $url,
            'secret_token' => self::webhookSecret($bot),
            'allowed_updates' => ['message', 'channel_post'],
        ]);
    }

    public function sendMessage(TelegramBot $bot, string $chatId, string $text): array
    {
        return $this->call($bot, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    /** Secret Telegram echoes in X-Telegram-Bot-Api-Secret-Token; derived, so nothing extra is stored. */
    public static function webhookSecret(TelegramBot $bot): string
    {
        return hash_hmac('sha256', 'telegram-webhook:'.$bot->id, (string) config('app.key'));
    }

    private function call(TelegramBot $bot, string $method, array $params = []): array
    {
        $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$bot->bot_token_encrypted}/{$method}", $params);
        $body = $response->json() ?? [];
        if (! ($body['ok'] ?? false)) {
            throw new RuntimeException('Telegram API error: '.($body['description'] ?? 'HTTP '.$response->status()));
        }

        return $body['result'] ?? [];
    }
}
