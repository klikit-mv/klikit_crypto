<?php

namespace App\Modules\Trading\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Trade;
use App\Models\TradingSymbol;
use App\Modules\Trading\Http\Requests\StoreOrderRequest;
use App\Modules\Trading\Http\Resources\OrderResource;
use App\Services\Trading\Data\OrderIntent;
use App\Services\Trading\TradingEngineService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request, string $market): AnonymousResourceCollection
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->whereHas('symbol', fn ($q) => $q->whereIn('market_type', $this->marketTypes($market)))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->with('symbol')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request, TradingEngineService $engine, string $market): JsonResponse
    {
        $symbol = TradingSymbol::findOrFail($request->integer('symbol_id'));
        $intent = OrderIntent::fromArray($request->user(), $symbol, $request->validated());

        $order = $engine->submit($intent, (string) $request->header('Idempotency-Key'));

        return (new OrderResource($order->load('symbol')))->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $market, Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('symbol'));
    }

    public function cancel(Request $request, TradingEngineService $engine, string $market, Order $order): OrderResource
    {
        $this->authorize('cancel', $order);

        return new OrderResource($engine->cancel($order)->load('symbol'));
    }

    public function trades(Request $request, string $market): JsonResponse
    {
        $trades = Trade::where('user_id', $request->user()->id)
            ->whereHas('symbol', fn ($q) => $q->whereIn('market_type', $this->marketTypes($market)))
            ->with('symbol:id,symbol,market_type')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($trades->through(fn (Trade $t) => [
            'id' => $t->id,
            'symbol' => $t->symbol->symbol,
            'mode' => $t->mode,
            'side' => $t->side,
            'quantity' => Decimal::display($t->quantity, 18),
            'entry_price' => Decimal::display($t->entry_price, 18),
            'exit_price' => $t->exit_price ? Decimal::display($t->exit_price, 18) : null,
            'fee' => Decimal::display($t->fee, 18),
            'pnl' => $t->pnl !== null ? Decimal::display($t->pnl, 8) : null,
            'pnl_currency' => $t->pnl_currency,
            'closed_at' => $t->closed_at,
            'created_at' => $t->created_at,
        ]));
    }

    private function marketTypes(string $market): array
    {
        return $market === 'forex' ? [TradingSymbol::FOREX, TradingSymbol::CFD] : [TradingSymbol::SPOT, TradingSymbol::FUTURES];
    }
}
