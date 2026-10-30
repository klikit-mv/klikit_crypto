<?php

namespace App\Modules\TradingBots\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TradingBot;
use App\Services\Bots\BotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BotAdminController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('trading.monitor');

        return view('admin.bots.index', [
            'bots' => TradingBot::with('user', 'strategy', 'symbol')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderByRaw("CASE status WHEN 'error' THEN 0 WHEN 'running' THEN 1 ELSE 2 END")
                ->latest('last_run_at')->paginate(50)->withQueryString(),
        ]);
    }

    /** Safety stop only; staff cannot start bots or trade on a user's behalf. */
    public function stop(Request $request, TradingBot $bot, BotService $bots): RedirectResponse
    {
        $this->authorize('trading.halt');
        $bots->stop($bot, 'stopped_by_admin');

        return back()->with('status', "Bot #{$bot->id} stopped.");
    }
}
