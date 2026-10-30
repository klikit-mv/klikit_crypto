@extends('layouts.app')
@section('title', 'Mining')
@section('content')
@if(session('agent_token'))
    <div class="alert alert-warning"><strong>Agent token (shown once):</strong> <code>{{ session('agent_token') }}</code><br>
        <span class="small">Run the agent on the rig: <code>MINING_AGENT_TOKEN=… PLATFORM_URL={{ url('/') }} python3 agent.py</code> (see <code>tools/mining-agent</code>).</span></div>
@endif
<div class="row g-3 mb-3">
    @foreach(['Revenue (30d)' => $totals['revenue'], 'Expenses (30d)' => $totals['expenses'], 'Net profit (30d)' => $totals['net']] as $label => $value)
        <div class="col-md-3"><div class="card stat-card"><div class="card-body"><div class="small text-secondary">{{ $label }}</div><div class="value num {{ $label === 'Net profit (30d)' ? ($value >= 0 ? 'text-buy' : 'text-sell') : '' }}">${{ number_format($value, 2) }}</div></div></div></div>
    @endforeach
    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><div class="small text-secondary">Devices online</div><div class="value">{{ $devices->where('status', 'online')->count() }} / {{ $devices->count() }}</div></div></div></div>
</div>
<p class="small text-secondary">Net profit = mining revenue − electricity − pool fees − other costs. Revenue comes only from your pool's reports (API sync or manual entry).</p>

<div class="card mb-3"><div class="card-header">Daily profitability (USD)</div><div class="card-body p-2"><div id="profit-chart" style="height:240px"></div></div></div>

<div class="card mb-3"><div class="card-header">Devices</div><table class="table table-sm mb-0 num"><thead><tr><th>Name</th><th>Type</th><th>Pool</th><th>Status</th><th class="text-end">Hashrate</th><th class="text-end">Temp</th><th class="text-end">Workers</th><th class="text-end">Power</th><th>Last seen</th></tr></thead><tbody>
@forelse($devices as $d)
    @php($l = $latest[$d->id])
    <tr><td><a href="{{ route('mining.devices.show', $d) }}">{{ $d->name }}</a></td><td>{{ strtoupper($d->device_type) }} {{ $d->model }}</td><td>{{ $d->pool?->name ?? '—' }}</td>
        <td><span class="badge bg-{{ $d->status === 'online' ? 'success' : 'secondary' }}">{{ $d->status }}</span></td>
        <td class="text-end">{{ \App\Support\HashrateFormatter::format($l['hashrate']) }}</td><td class="text-end {{ ($l['temperature'] ?? 0) > 85 ? 'text-sell' : '' }}">{{ $l['temperature'] !== null ? $l['temperature'].'°C' : '—' }}</td>
        <td class="text-end">{{ $l['workers_online'] }}/{{ $l['workers'] }}</td><td class="text-end">{{ $d->power_watts }} W</td><td>{{ $d->last_seen_at?->diffForHumans() ?? 'never' }}</td></tr>
@empty<tr><td colspan="9" class="text-secondary">No devices yet.</td></tr>@endforelse
</tbody></table></div>

<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-header">Add device</div><div class="card-body">
        <form method="post" action="{{ route('mining.devices.store') }}" class="row g-2">@csrf
            <div class="col-12"><input name="name" class="form-control form-control-sm" placeholder="Name, e.g. Rig 1" required></div>
            <div class="col-6"><select name="device_type" class="form-select form-select-sm"><option value="asic">ASIC</option><option value="gpu">GPU rig</option><option value="cpu">CPU</option></select></div>
            <div class="col-6"><input name="model" class="form-control form-control-sm" placeholder="Model"></div>
            <div class="col-12"><select name="pool_id" class="form-select form-select-sm"><option value="">No pool</option>@foreach($pools as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->coin }})</option>@endforeach</select></div>
            <div class="col-6"><input name="power_watts" class="form-control form-control-sm" placeholder="Power (W)" required></div>
            <div class="col-6"><input name="electricity_rate" class="form-control form-control-sm" placeholder="USD per kWh" required></div>
            <div class="col-12"><button class="btn btn-sm btn-primary w-100">Add device</button></div>
        </form></div></div></div>
    <div class="col-lg-4"><div class="card mb-3"><div class="card-header">Pool accounts</div><div class="card-body">
        <ul class="small">@forelse($poolAccounts as $a)<li>{{ $a->pool->name }}: {{ $a->account_name }} · synced {{ $a->last_synced_at?->diffForHumans() ?? 'never' }}</li>@empty<li class="text-secondary">None linked.</li>@endforelse</ul>
        <form method="post" action="{{ route('mining.pool-accounts.store') }}" class="row g-2">@csrf
            <div class="col-12"><select name="pool_id" class="form-select form-select-sm">@foreach($pools as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
            <div class="col-6"><input name="account_name" class="form-control form-control-sm" placeholder="Account / wallet" required></div>
            <div class="col-6"><input name="api_key" class="form-control form-control-sm" placeholder="Read-only API key"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Link pool account</button></div>
        </form></div></div>
        <div class="card"><div class="card-header">Record earning manually</div><div class="card-body">
        <form method="post" action="{{ route('mining.earnings.store') }}" class="row g-2">@csrf
            <div class="col-12"><select name="pool_id" class="form-select form-select-sm"><option value="">No pool</option>@foreach($pools as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
            <div class="col-4"><input name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
            <div class="col-3"><input name="currency" class="form-control form-control-sm" placeholder="BTC" required></div>
            <div class="col-5"><input type="date" name="date" value="{{ now()->toDateString() }}" class="form-control form-control-sm"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div>
        </form></div></div></div>
    <div class="col-lg-4"><div class="card mb-3"><div class="card-header">Recent earnings</div><table class="table table-sm mb-0 small num"><tbody>
        @forelse($earnings as $e)<tr><td>{{ $e->date }}</td><td class="text-end">{{ \App\Support\Decimal::display($e->amount) }} {{ $e->currency }}</td></tr>@empty<tr><td class="text-secondary">None.</td></tr>@endforelse
    </tbody></table></div>
    <div class="card"><div class="card-header">Payouts</div><table class="table table-sm mb-0 small num"><tbody>
        @forelse($payouts as $p)<tr><td>{{ $p->paid_at?->toDateString() }}</td><td>{{ \App\Support\Decimal::display($p->amount) }} {{ $p->currency }}</td><td class="text-truncate" style="max-width:120px">{{ $p->transaction_hash }}</td></tr>@empty<tr><td class="text-secondary">None.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(() => {
    const rows = @json($daily);
    const chart = LightweightCharts.createChart(document.getElementById('profit-chart'), { height: 240, layout: { background: { color: 'transparent' }, textColor: '#9aa4b2' }, grid: { vertLines: { color: '#1f2731' }, horzLines: { color: '#1f2731' } } });
    chart.addHistogramSeries({ title: 'Net' }).setData(rows.map(r => ({ time: r.date, value: r.net_profit, color: r.net_profit >= 0 ? '#26a69a' : '#ef5350' })));
    chart.addLineSeries({ color: '#4ea8de', lineWidth: 1, title: 'Revenue' }).setData(rows.map(r => ({ time: r.date, value: r.revenue })));
    chart.timeScale().fitContent();
})();
</script>
@endsection
