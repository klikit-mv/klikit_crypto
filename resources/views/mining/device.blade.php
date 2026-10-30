@extends('layouts.app')
@section('title', $device->name)
@section('content')
<div class="d-flex gap-2 mb-3">
    <span class="badge bg-{{ $device->status === 'online' ? 'success' : 'secondary' }} align-self-center">{{ $device->status }}</span>
    <span class="small text-secondary align-self-center">{{ strtoupper($device->device_type) }} {{ $device->model }} · {{ $device->power_watts }} W · ${{ $device->electricity_rate }}/kWh · pool {{ $device->pool?->name ?? '—' }}</span>
    <form method="post" action="{{ route('mining.devices.token', $device) }}" class="ms-auto">@csrf<button class="btn btn-sm btn-outline-secondary">New agent token</button></form>
    <form method="post" action="{{ route('mining.devices.destroy', $device) }}" onsubmit="return confirm('Remove device and its history?')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Remove</button></form>
</div>
@if(session('agent_token'))<div class="alert alert-warning">New agent token (shown once): <code>{{ session('agent_token') }}</code></div>@endif
<div class="card mb-3"><div class="card-header">Hashrate (24h)</div><div class="card-body p-2"><div id="hash-chart" style="height:260px"></div></div></div>
<div class="row g-3">
    <div class="col-lg-5"><div class="card mb-3"><div class="card-header">Workers</div><table class="table table-sm mb-0 small"><tbody>
        @forelse($device->workers as $w)<tr><td>{{ $w->worker_name }}</td><td>{{ $w->status }}</td><td>{{ $w->last_seen_at?->diffForHumans() }}</td></tr>@empty<tr><td class="text-secondary">No worker has reported yet.</td></tr>@endforelse
    </tbody></table></div>
    <div class="card"><div class="card-header">Add other cost</div><div class="card-body">
        <form method="post" action="{{ route('mining.devices.expenses', $device) }}" class="row g-2">@csrf
            <div class="col-5"><input name="amount" class="form-control form-control-sm" placeholder="USD" required></div>
            <div class="col-4"><input type="date" name="date" value="{{ now()->toDateString() }}" class="form-control form-control-sm"></div>
            <div class="col-3"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div>
        </form></div></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header">Profitability</div><table class="table table-sm mb-0 small num"><thead><tr><th>Date</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net</th></tr></thead><tbody>
        @forelse($profit as $p)<tr><td>{{ $p->date }}</td><td class="text-end">${{ \App\Support\Decimal::money($p->revenue) }}</td><td class="text-end">${{ \App\Support\Decimal::money($p->expenses) }}</td>
            <td class="text-end {{ str_starts_with((string) $p->net_profit, '-') ? 'text-sell' : 'text-buy' }}">${{ \App\Support\Decimal::money($p->net_profit) }}</td></tr>
        @empty<tr><td colspan="4" class="text-secondary">Not computed yet.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
    const chart = LightweightCharts.createChart(document.getElementById('hash-chart'), { height: 260, layout: { background: { color: 'transparent' }, textColor: '#9aa4b2' }, grid: { vertLines: { color: '#1f2731' }, horzLines: { color: '#1f2731' } }, timeScale: { timeVisible: true } });
    chart.addAreaSeries({ lineColor: '#26a69a', topColor: 'rgba(38,166,154,.3)', bottomColor: 'rgba(38,166,154,0)' }).setData(@json($series));
    chart.timeScale().fitContent();
</script>
@endsection
