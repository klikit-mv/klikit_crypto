<?php

namespace App\Modules\Signals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SignalLog;
use App\Models\TelegramBot;
use App\Models\TelegramChannel;
use App\Services\Access\AuditLogger;
use App\Services\Signals\TelegramBotApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class TelegramAdminController extends Controller
{
    public function index(): View
    {
        $this->authorize('telegram.manage');

        return view('admin.telegram.index', [
            'bots' => TelegramBot::whereNull('user_id')->get(),
            'channels' => TelegramChannel::whereNull('user_id')->withCount('subscriptions', 'signals')->get(),
            'logs' => SignalLog::latest('id')->limit(30)->get(),
        ]);
    }

    public function storeBot(Request $request, TelegramBotApi $api, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('telegram.manage');
        $data = $request->validate(['bot_token' => ['required', 'string', 'regex:/^\d+:[A-Za-z0-9_-]{30,}$/']]);

        $bot = TelegramBot::create(['bot_token_encrypted' => $data['bot_token'], 'status' => 'inactive']);
        try {
            $me = $api->getMe($bot);
            $api->setWebhook($bot, route('telegram.webhook', $bot));
            $bot->update(['username' => $me['username'] ?? null, 'status' => 'active']);
        } catch (Throwable $e) {
            $bot->delete();

            return back()->withErrors(['bot_token' => $e->getMessage()]);
        }
        $audit->log('admin.telegram_bot_added', $request->user(), $bot);

        return back()->with('status', "Bot @{$bot->username} connected and webhook registered.");
    }

    public function storeChannel(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('telegram.manage');
        $data = $request->validate([
            'channel_name' => ['required', 'string', 'max:255'],
            'channel_id' => ['required', 'string', 'max:64'],
            'telegram_bot_id' => ['nullable', 'exists:telegram_bots,id'],
            'signal_ttl_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        $channel = TelegramChannel::create($data + ['webhook_secret' => Str::random(40), 'is_active' => true]);
        $audit->log('admin.telegram_channel_added', $request->user(), $channel);

        return back()->with('status', 'Channel added.');
    }

    public function toggleChannel(Request $request, TelegramChannel $channel): RedirectResponse
    {
        $this->authorize('telegram.manage');
        $channel->update(['is_active' => ! $channel->is_active]);

        return back()->with('status', $channel->channel_name.($channel->is_active ? ' enabled.' : ' disabled.'));
    }
}
