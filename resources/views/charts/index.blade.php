@extends('layouts.app')
@section('title', 'Chart analysis')
@section('content')
<div class="card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" id="chart-form">
        <div class="col-md-3"><label class="form-label small">Instrument</label>
            <select id="symbol" class="form-select">@foreach($symbols as $s)<option value="{{ $s->id }}" @selected($s->id === $selected)>{{ $s->symbol }} ({{ $s->market_type }})</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small">Interval</label>
            <select id="interval" class="form-select">@foreach(\App\Models\MarketCandle::INTERVALS as $i)<option @selected($i === $interval)>{{ $i }}</option>@endforeach</select></div>
        <div class="col-md-5"><label class="form-label small">Indicators</label><div>
            <label class="me-2"><input type="checkbox" class="ind" value="ema:20" checked> EMA 20</label>
            <label class="me-2"><input type="checkbox" class="ind" value="ema:50"> EMA 50</label>
            <label class="me-2 {{ $advanced ? '' : 'text-secondary' }}"><input type="checkbox" class="ind" value="rsi" {{ $advanced ? 'checked' : 'disabled' }}> RSI</label>
            <label class="{{ $advanced ? '' : 'text-secondary' }}"><input type="checkbox" class="ind" value="macd" {{ $advanced ? 'checked' : 'disabled' }}> MACD</label>
            @unless($advanced)<span class="small text-warning ms-2">RSI/MACD need a Pro plan</span>@endunless
        </div></div>
    </form>
</div></div>
<div class="card mb-3"><div class="card-body p-2">
    <div id="price-chart" style="height:420px"></div>
    <div id="rsi-chart" style="height:120px"></div>
    <div id="macd-chart" style="height:120px"></div>
    <div id="chart-empty" class="text-secondary p-3 d-none">No candles yet for this interval. Run <code>php artisan market:candles</code> or wait for live prices.</div>
</div></div>

<div class="row g-3">
    <div class="col-lg-5"><div class="card"><div class="card-header">New alert</div><div class="card-body">
        <form method="post" action="{{ route('charts.alerts.store') }}" class="row g-2">@csrf
            <div class="col-12"><select name="symbol_id" class="form-select">@foreach($symbols as $s)<option value="{{ $s->id }}" @selected($s->id === $selected)>{{ $s->symbol }} ({{ $s->market_type }})</option>@endforeach</select></div>
            <div class="col-6"><select name="condition" class="form-select">
                <option value="price_above">Price above</option><option value="price_below">Price below</option>
                <option value="rsi_above" @disabled(! $advanced)>RSI above</option><option value="rsi_below" @disabled(! $advanced)>RSI below</option></select></div>
            <div class="col-6"><input name="target" class="form-control" placeholder="Target" required></div>
            <div class="col-12"><input name="note" class="form-control" placeholder="Note (optional)"></div>
            <div class="col-12"><button class="btn btn-primary w-100">Create alert</button></div>
        </form>
    </div></div></div>
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header">My alerts</div><table class="table table-sm mb-0 num"><tbody>
            @forelse($alerts as $a)
                <tr><td>{{ $a->symbol->symbol }}</td><td>{{ str_replace('_', ' ', $a->condition) }} {{ \App\Support\Decimal::display($a->target) }}</td><td>{{ $a->status }}@if($a->triggered_at) at {{ $a->triggered_at->format('m-d H:i') }}@endif</td>
                    <td class="text-end">@if($a->status === 'active')<form method="post" action="{{ route('charts.alerts.destroy', $a) }}">@csrf @method('delete')<button class="btn btn-sm btn-outline-secondary">Cancel</button></form>@endif</td></tr>
            @empty<tr><td class="text-secondary">No alerts.</td></tr>@endforelse
        </tbody></table></div>
        <div class="card"><div class="card-header d-flex justify-content-between">Notifications
            <form method="post" action="{{ route('notifications.read') }}">@csrf<button class="btn btn-sm btn-link p-0">Mark all read</button></form></div>
            <ul class="list-group list-group-flush small">
                @forelse($notifications as $n)<li class="list-group-item {{ $n->read_at ? 'text-secondary' : '' }}">{{ $n->data['message'] ?? '' }} <span class="text-secondary">· {{ $n->created_at->diffForHumans() }}</span></li>
                @empty<li class="list-group-item text-secondary">Nothing yet.</li>@endforelse
            </ul></div>
    </div>
</div>

<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(() => {
    const opts = { layout: { background: { color: 'transparent' }, textColor: '#9aa4b2' }, grid: { vertLines: { color: '#1f2731' }, horzLines: { color: '#1f2731' } }, timeScale: { timeVisible: true } };
    const make = (id) => LightweightCharts.createChart(document.getElementById(id), { ...opts, height: document.getElementById(id).clientHeight });
    const price = make('price-chart'), rsi = make('rsi-chart'), macd = make('macd-chart');
    const candles = price.addCandlestickSeries({ upColor: '#26a69a', downColor: '#ef5350', borderVisible: false, wickUpColor: '#26a69a', wickDownColor: '#ef5350' });
    const colors = ['#f5c542', '#4ea8de', '#c77dff'];
    let overlays = [], rsiLine = rsi.addLineSeries({ color: '#c77dff', lineWidth: 1 });
    const macdLine = macd.addLineSeries({ color: '#4ea8de', lineWidth: 1 }), signalLine = macd.addLineSeries({ color: '#f5c542', lineWidth: 1 }), hist = macd.addHistogramSeries({ color: '#5c6773' });
    async function load() {
        const inds = [...document.querySelectorAll('.ind:checked')].map(i => i.value).join(',');
        const q = new URLSearchParams({ symbol_id: symbol.value, interval: interval.value, indicators: inds });
        const res = await fetch('{{ route('charts.data') }}?' + q, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        document.getElementById('chart-empty').classList.toggle('d-none', data.candles.length > 0);
        candles.setData(data.candles);
        overlays.forEach(s => price.removeSeries(s)); overlays = [];
        Object.entries(data.indicators).filter(([k]) => /^(ema|sma)/.test(k)).forEach(([k, v], i) => {
            const s = price.addLineSeries({ color: colors[i % colors.length], lineWidth: 1, title: k.toUpperCase() }); s.setData(v); overlays.push(s);
        });
        rsiLine.setData(data.indicators.rsi || []);
        document.getElementById('rsi-chart').style.display = data.indicators.rsi ? '' : 'none';
        const m = data.indicators.macd;
        macdLine.setData(m ? m.macd : []); signalLine.setData(m ? m.signal : []);
        hist.setData(m ? m.histogram.map(p => ({ ...p, color: p.value >= 0 ? '#26a69a' : '#ef5350' })) : []);
        document.getElementById('macd-chart').style.display = m ? '' : 'none';
        [price, rsi, macd].forEach(c => c.timeScale().fitContent());
    }
    document.querySelectorAll('#symbol, #interval, .ind').forEach(el => el.addEventListener('change', load));
    load(); setInterval(load, 60000);
})();
</script>
@endsection
