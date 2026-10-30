@extends('layouts.app')
@section('title', 'Strategy backtesting')
@section('content')
<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-header">Run backtest</div><div class="card-body">
        <form method="post" action="{{ route('bots.backtests.run') }}">@csrf
            <div class="mb-2"><select name="strategy_id" id="bt-strategy" class="form-select">@foreach($strategies as $st)<option value="{{ $st->id }}" @selected(old('strategy_id') == $st->id)>{{ $st->name }}</option>@endforeach</select></div>
            <div class="mb-2"><select name="symbol_id" class="form-select">@foreach($symbols as $sym)<option value="{{ $sym->id }}" @selected(old('symbol_id') == $sym->id)>{{ $sym->symbol }} ({{ $sym->market_type }})</option>@endforeach</select></div>
            <div class="row g-2 mb-2"><div class="col"><input type="date" name="period_start" value="{{ old('period_start', now()->subMonths(3)->toDateString()) }}" class="form-control"></div>
                <div class="col"><input type="date" name="period_end" value="{{ old('period_end', now()->toDateString()) }}" class="form-control"></div></div>
            <div class="mb-2"><input name="starting_cash" value="{{ old('starting_cash', 10000) }}" class="form-control" title="Starting cash"></div>
            <div class="mb-3">@include('bots._params', ['selectId' => 'bt-strategy', 'values' => old('parameters', [])])</div>
            <button class="btn btn-primary w-100">Run</button>
            <div class="form-text">Uses stored candles (back-fill with <code>php artisan market:candles --interval=1h --limit=1000</code>). Fills at candle close with paper fees; no slippage.</div>
        </form>
    </div></div>
    <div class="list-group mt-3">@foreach($backtests as $b)
        <a href="?id={{ $b->id }}" class="list-group-item list-group-item-action small {{ $selected?->id === $b->id ? 'active' : '' }}">#{{ $b->id }} {{ $b->strategy->name }} · {{ $b->period_start->toDateString() }}→{{ $b->period_end->toDateString() }} · {{ $b->results['return_pct'] ?? '?' }}%</a>
    @endforeach</div></div>
    <div class="col-lg-8">
        @if($selected)
            @php($r = $selected->results)
            <div class="row g-2 mb-3">
                @foreach(['Return' => $r['return_pct'].'%', 'Buy & hold' => $r['buy_and_hold_return_pct'].'%', 'Max drawdown' => $r['max_drawdown_pct'].'%', 'Trades' => $r['trades'], 'Win rate' => $r['win_rate'] !== null ? $r['win_rate'].'%' : '—', 'Final equity' => number_format($r['final_equity'], 2)] as $label => $value)
                    <div class="col-md-2"><div class="card stat-card"><div class="card-body p-2"><div class="small text-secondary">{{ $label }}</div><div class="fw-semibold">{{ $value }}</div></div></div></div>
                @endforeach
            </div>
            <div class="card mb-3"><div class="card-body p-2"><div id="equity" style="height:300px"></div></div></div>
            <div class="card"><div class="card-header">Trades (last {{ count($r['trade_log']) }})</div><table class="table table-sm mb-0 small num"><tbody>
                @foreach(array_reverse($r['trade_log']) as $t)
                    <tr><td>{{ \Illuminate\Support\Carbon::createFromTimestamp($t['time'])->format('Y-m-d H:i') }}</td><td class="text-{{ $t['side'] }}">{{ strtoupper($t['side']) }}</td><td>{{ $t['price'] }}</td><td>{{ $t['quantity'] }}</td><td>{{ $t['pnl'] ?? '' }}</td><td>{{ $t['reason'] }}</td></tr>
                @endforeach
            </tbody></table></div>
            <script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
            <script>
                const chart = LightweightCharts.createChart(document.getElementById('equity'), { height: 300, layout: { background: { color: 'transparent' }, textColor: '#9aa4b2' }, grid: { vertLines: { color: '#1f2731' }, horzLines: { color: '#1f2731' } }, timeScale: { timeVisible: true } });
                chart.addAreaSeries({ lineColor: '#4ea8de', topColor: 'rgba(78,168,222,.3)', bottomColor: 'rgba(78,168,222,0)' }).setData(@json($r['equity_curve']));
                chart.timeScale().fitContent();
            </script>
        @else
            <div class="text-secondary">Run a backtest to see results.</div>
        @endif
    </div>
</div>
@endsection
