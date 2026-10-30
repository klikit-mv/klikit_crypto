<?php

namespace App\Modules\Trading\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Access\AuditLogger;
use App\Services\Trading\RiskManagementService;
use App\Services\Trading\TradingEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RiskProfileController extends Controller
{
    public function show(Request $request, RiskManagementService $risk): JsonResponse
    {
        return response()->json(['data' => $risk->profileFor($request->user())]);
    }

    public function update(Request $request, RiskManagementService $risk, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'max_amount_per_trade' => ['sometimes', 'numeric', 'min:0'],
            'max_daily_loss' => ['sometimes', 'numeric', 'min:0'],
            'max_open_positions' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'max_leverage' => ['sometimes', 'numeric', 'min:1', 'max:'.config('trading.limits.max_leverage')],
            'require_stop_loss' => ['sometimes', 'boolean'],
            'max_total_exposure' => ['sometimes', 'numeric', 'min:0'],
            'trading_enabled' => ['sometimes', 'boolean'],
        ]);

        $profile = $risk->profileFor($request->user());
        $profile->fill($data);
        if (array_key_exists('trading_enabled', $data)) {
            $profile->disabled_reason = $data['trading_enabled'] ? null : 'Disabled by user';
        }
        $profile->save();
        $audit->log('risk_profile.updated', $request->user(), $profile, $data);

        return response()->json(['data' => $profile]);
    }

    /** Emergency shutdown: disables trading and cancels the user's working orders. */
    public function emergencyStop(Request $request, RiskManagementService $risk, TradingEngineService $engine, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $risk->profileFor($user)->update(['trading_enabled' => false, 'disabled_reason' => 'Emergency stop']);

        $cancelled = 0;
        foreach ($user->orders()->whereIn('status', Order::ACTIVE_STATUSES)->get() as $order) {
            try {
                $engine->cancel($order);
                $cancelled++;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $user->tradingBots()->where('status', 'running')->update(['status' => 'stopped']);
        $audit->log('trading.emergency_stop', $user, $user, ['cancelled_orders' => $cancelled]);

        return response()->json(['message' => 'Trading disabled.', 'cancelled_orders' => $cancelled]);
    }
}
