<?php

namespace App\Modules\Trading\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Position;
use App\Models\TradingSymbol;
use App\Modules\Trading\Http\Requests\StoreOrderRequest;
use App\Services\Access\AuditLogger;
use App\Services\Trading\Data\OrderIntent;
use App\Services\Trading\PortfolioService;
use App\Services\Trading\RiskManagementService;
use App\Services\Trading\TradingEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TradingController extends Controller
{
    public function index(Request $request, PortfolioService $portfolio): View
    {
        $user = $request->user();

        return view('trading.index', [
            'symbols' => TradingSymbol::active()->with('latestPrice')->orderBy('market_type')->orderBy('symbol')->get(),
            'orders' => Order::where('user_id', $user->id)->with('symbol')->latest()->limit(25)->get(),
            'portfolio' => $portfolio->summary($user),
            // A fresh key per rendered form makes accidental double submissions idempotent.
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreOrderRequest $request, TradingEngineService $engine): RedirectResponse
    {
        $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $symbol = TradingSymbol::findOrFail($request->integer('symbol_id'));
        $order = $engine->submit(OrderIntent::fromArray($request->user(), $symbol, $request->validated() + ['mode' => 'paper']), $request->input('idempotency_key'));

        return redirect()->route('trading.index')->with('status', "Order {$order->side} {$symbol->symbol}: {$order->status}.");
    }

    public function cancel(Request $request, Order $order, TradingEngineService $engine): RedirectResponse
    {
        $this->authorize('cancel', $order);
        $engine->cancel($order);

        return back()->with('status', 'Order cancelled.');
    }

    public function closePosition(Request $request, Position $position, TradingEngineService $engine): RedirectResponse
    {
        $this->authorize('close', $position);
        $engine->closePosition($position);

        return back()->with('status', 'Position closed.');
    }

    public function risk(Request $request, RiskManagementService $risk): View
    {
        return view('trading.risk', ['profile' => $risk->profileFor($request->user())]);
    }

    public function updateRisk(Request $request, RiskManagementService $risk, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'max_amount_per_trade' => ['required', 'numeric', 'min:0'],
            'max_daily_loss' => ['required', 'numeric', 'min:0'],
            'max_open_positions' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_leverage' => ['required', 'numeric', 'min:1', 'max:'.config('trading.limits.max_leverage')],
            'max_total_exposure' => ['required', 'numeric', 'min:0'],
        ]);
        $data['require_stop_loss'] = $request->boolean('require_stop_loss');
        $data['trading_enabled'] = $request->boolean('trading_enabled');

        $profile = $risk->profileFor($request->user());
        $profile->fill($data + ['disabled_reason' => $data['trading_enabled'] ? null : 'Disabled by user'])->save();
        $audit->log('risk_profile.updated', $request->user(), $profile, $data);

        return back()->with('status', 'Risk settings saved.');
    }
}
