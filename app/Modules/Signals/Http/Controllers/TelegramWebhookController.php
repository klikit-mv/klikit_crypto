<?php

namespace App\Modules\Signals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use App\Models\TelegramChannel;
use App\Services\Signals\SignalService;
use App\Services\Signals\TelegramBotApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound signal sources:
 *  - Telegram Bot API webhook (the bot must be a member/admin of the channel)
 *  - Generic ingest endpoint for forwarders (e.g. a user-account client reading channels
 *    where a bot cannot be added), authenticated with the channel's ingest secret.
 */
class TelegramWebhookController extends Controller
{
    public function telegram(Request $request, TelegramBot $bot, SignalService $signals): JsonResponse
    {
        abort_unless(hash_equals(TelegramBotApi::webhookSecret($bot), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $post = $request->input('channel_post') ?? $request->input('message');
        $text = $post['text'] ?? $post['caption'] ?? null;
        $chatId = isset($post['chat']['id']) ? (string) $post['chat']['id'] : null;

        if ($bot->status === 'active' && $text && $chatId) {
            $channel = TelegramChannel::where('telegram_bot_id', $bot->id)->where('channel_id', $chatId)->where('is_active', true)->first();
            if ($channel) {
                $signals->ingest($channel, $text, (string) ($post['message_id'] ?? ''));
            }
        }

        // Always 200 so Telegram does not keep retrying messages we chose to ignore.
        return response()->json(['ok' => true]);
    }

    public function ingest(Request $request, TelegramChannel $channel, SignalService $signals): JsonResponse
    {
        abort_unless($channel->webhook_secret && hash_equals($channel->webhook_secret, (string) $request->header('X-Signal-Secret')), 403);
        abort_unless($channel->is_active, 404);
        $data = $request->validate(['text' => ['required', 'string', 'max:4096'], 'message_id' => ['nullable', 'string', 'max:64']]);

        $signal = $signals->ingest($channel, $data['text'], $data['message_id'] ?? null);

        return response()->json(['accepted' => $signal !== null, 'signal_id' => $signal?->id]);
    }
}
