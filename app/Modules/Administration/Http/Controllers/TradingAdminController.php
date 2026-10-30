<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\Order;
use App\Services\Access\AuditLogger;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TradingAdminController extends Controller
{
    public function integrations(): View
    {
        $this->authorize('integrations.manage');

        return view('admin.trading.integrations', [
            'exchanges' => Exchange::withCount('accounts')->orderBy('name')->get(),
            'brokers' => Broker::withCount('accounts')->orderBy('name')->get(),
        ]);
    }

    public function updateIntegration(Request $request, string $type, int $id, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('integrations.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['enabled', 'disabled', 'maintenance'])]]);
        $model = ($type === 'broker' ? Broker::class : Exchange::class)::findOrFail($id);
        $model->update($data);
        $audit->log('admin.integration_status', $request->user(), $model, $data);

        return back()->with('status', "{$model->name} is now {$data['status']}.");
    }

    public function controls(PlatformSettings $settings): View
    {
        abort_unless(request()->user()->can('trading.limits') || request()->user()->can('trading.halt'), 403);

        return view('admin.trading.controls', [
            'halted' => $settings->tradingHalted(),
            'limits' => collect(array_keys(config('trading.limits')))->mapWithKeys(fn ($k) => [$k => $settings->limit($k)]),
            'liveEnabled' => (bool) config('trading.live_trading_enabled'),
        ]);
    }

    public function updateLimits(Request $request, PlatformSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('trading.limits');
        $data = $request->validate([
            'max_order_notional' => ['required', 'numeric', 'min:0'],
            'max_leverage' => ['required', 'numeric', 'min:1', 'max:500'],
            'max_open_positions' => ['required', 'integer', 'min:0'],
        ]);
        foreach ($data as $key => $value) {
            $settings->set("trading.limits.$key", (string) $value);
        }
        $audit->log('admin.trading_limits', $request->user(), null, $data);

        return back()->with('status', 'Platform limits updated.');
    }

    public function toggleHalt(Request $request, PlatformSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('trading.halt');
        $halt = $request->boolean('halt');
        $settings->set('trading.halted', $halt);
        $audit->log($halt ? 'admin.trading_halted' : 'admin.trading_resumed', $request->user());

        return back()->with('status', $halt ? 'Platform trading halted.' : 'Platform trading resumed.');
    }

    public function orders(Request $request): View
    {
        $this->authorize('trading.monitor');

        return view('admin.trading.orders', [
            'orders' => Order::with('user', 'symbol')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(50)->withQueryString(),
        ]);
    }
}
