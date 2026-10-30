@extends('layouts.app')
@section('title', 'Paper trading')
@section('content')
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header">New order</div><div class="card-body">
            <form method="post" action="{{ route('trading.orders.store') }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                <div class="mb-2"><label class="form-label">Instrument</label>
                    <select name="symbol_id" class="form-select" required>
                        @foreach($symbols->groupBy('market_type') as $type => $group)
                            <optgroup label="{{ $type }}">
                                @foreach($group as $s)
                                    <option value="{{ $s->id }}" @selected(old('symbol_id') == $s->id)>{{ $s->symbol }} — {{ $s->latestPrice ? \App\Support\Decimal::display($s->latestPrice->price) : 'no price' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select></div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label">Side</label><select name="side" class="form-select"><option value="buy">Buy / Long</option><option value="sell" @selected(old('side') === 'sell')>Sell / Short</option></select></div>
                    <div class="col"><label class="form-label">Type</label><select name="type" class="form-select"><option value="market">Market</option><option value="limit" @selected(old('type') === 'limit')>Limit</option></select></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label">Quantity</label><input name="quantity" value="{{ old('quantity') }}" class="form-control" inputmode="decimal" required></div>
                    <div class="col"><label class="form-label">Limit price</label><input name="price" value="{{ old('price') }}" class="form-control" inputmode="decimal"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label">Stop-loss</label><input name="stop_loss" value="{{ old('stop_loss') }}" class="form-control" inputmode="decimal"></div>
                    <div class="col"><label class="form-label">Take-profit</label><input name="take_profit" value="{{ old('take_profit') }}" class="form-control" inputmode="decimal"></div>
                </div>
                <div class="mb-3"><label class="form-label">Leverage (futures / forex)</label><input name="leverage" value="{{ old('leverage', 1) }}" class="form-control" inputmode="decimal"></div>
                <button class="btn btn-primary w-100">Submit order</button>
                <div class="form-text">Forex quantities are in lots. Every order passes the risk engine (<a href="{{ route('trading.risk') }}">your limits</a>).</div>
            </form>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-header">Open positions</div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Symbol</th><th>Side</th><th class="text-end">Qty</th><th class="text-end">Entry</th><th class="text-end">Margin</th><th class="text-end">Unrealized</th><th></th></tr></thead><tbody>
            @forelse($portfolio['positions'] as $pos)
                <tr><td>{{ $pos['symbol'] }}</td><td class="{{ $pos['side'] === 'long' ? 'text-buy' : 'text-sell' }}">{{ $pos['side'] }} {{ $pos['leverage'] }}x</td><td class="text-end">{{ $pos['quantity'] }}</td><td class="text-end">{{ $pos['average_price'] }}</td><td class="text-end">{{ $pos['margin'] }}</td><td class="text-end">{{ $pos['unrealized_pnl'] ?? '—' }} {{ $pos['currency'] }}</td>
                    <td class="text-end"><form method="post" action="{{ route('trading.positions.close', $pos['id']) }}">@csrf<button class="btn btn-sm btn-outline-warning">Close</button></form></td></tr>
            @empty<tr><td colspan="7" class="text-secondary">No open positions.</td></tr>@endforelse
            </tbody></table>
        </div>
        <div class="card"><div class="card-header">Orders</div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Time</th><th>Symbol</th><th>Side</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Filled @</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse($orders as $o)
                <tr><td>{{ $o->created_at->format('m-d H:i:s') }}</td><td>{{ $o->symbol->symbol }}</td><td class="text-{{ $o->side }}">{{ $o->side }} {{ $o->type }}</td>
                    <td class="text-end">{{ \App\Support\Decimal::display($o->quantity) }}</td><td class="text-end">{{ $o->price ? \App\Support\Decimal::display($o->price) : 'mkt' }}</td>
                    <td class="text-end">{{ $o->average_fill_price ? \App\Support\Decimal::display($o->average_fill_price) : '—' }}</td>
                    <td>{{ $o->status }} @if($o->reject_reason)<div class="small text-danger">{{ $o->reject_reason }}</div>@endif</td>
                    <td class="text-end">@if($o->isActive())<form method="post" action="{{ route('trading.orders.cancel', $o) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Cancel</button></form>@endif</td></tr>
            @empty<tr><td colspan="8" class="text-secondary">No orders yet.</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
</div>
@endsection
