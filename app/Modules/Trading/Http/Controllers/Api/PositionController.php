<?php

namespace App\Modules\Trading\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\Trading\PortfolioService;
use App\Services\Trading\TradingEngineService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function index(Request $request, PortfolioService $portfolio): JsonResponse
    {
        $mode = $request->query('mode') === 'live' ? 'live' : 'paper';

        return response()->json(['data' => $portfolio->summary($request->user(), $mode)['positions']]);
    }

    public function close(Request $request, TradingEngineService $engine, Position $position): JsonResponse
    {
        $this->authorize('close', $position);
        $position = $engine->closePosition($position);

        return response()->json(['data' => [
            'id' => $position->id,
            'status' => $position->status,
            'realized_pnl' => Decimal::display($position->realized_pnl, 8),
            'currency' => $position->margin_currency,
            'closed_at' => $position->closed_at,
        ]]);
    }
}
