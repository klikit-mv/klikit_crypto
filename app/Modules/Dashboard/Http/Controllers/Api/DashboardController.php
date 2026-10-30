<?php

namespace App\Modules\Dashboard\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Trade;
use App\Modules\Dashboard\DashboardService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function overview(Request $request, DashboardService $dashboard): JsonResponse
    {
        $mode = $request->query('mode') === 'live' ? 'live' : 'paper';

        return response()->json($dashboard->overview($request->user(), $mode));
    }

    public function statistics(Request $request): JsonResponse
    {
        $user = $request->user();
        $closed = Trade::where('user_id', $user->id)->whereNotNull('pnl')->get(['pnl']);
        $wins = $closed->filter(fn ($t) => Decimal::of($t->pnl)->isPositive())->count();

        return response()->json([
            'orders_total' => Order::where('user_id', $user->id)->count(),
            'orders_filled' => Order::where('user_id', $user->id)->where('status', Order::FILLED)->count(),
            'closed_trades' => $closed->count(),
            'win_rate' => $closed->count() ? round($wins / $closed->count() * 100, 2) : null,
        ]);
    }
}
