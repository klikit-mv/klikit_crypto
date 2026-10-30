@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php($p = $overview['portfolio'])
@foreach($overview['announcements'] as $a)
    <div class="alert alert-{{ ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger'][$a->level] }}"><strong>{{ $a->title }}</strong> — {{ $a->body }}</div>
@endforeach
<div class="alert alert-secondary py-2 small">Paper trading mode: orders execute against live market prices with simulated funds.</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Total portfolio', '$'.\App\Support\Decimal::money($p['total_usd'])],
        ['Available balance', '$'.\App\Support\Decimal::money($p['available_usd'])],
        ['Unrealized P&L', '$'.\App\Support\Decimal::money($p['unrealized_pnl_usd'])],
        ['Realized P&L', '$'.\App\Support\Decimal::money($p['realized_pnl_usd'])],
        ['Active bots', $overview['bots']['active'].' / '.$overview['bots']['total']],
        ['Subscription', $overview['subscription']['plan'] ?? 'Free'],
    ] as [$label, $value])
        <div class="col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body">
            <div class="text-secondary small">{{ $label }}</div><div class="value num">{{ $value }}</div>
            @if($label === 'Subscription' && $overview['subscription'])<div class="small text-secondary">until {{ $overview['subscription']['expires_at']->toDateString() }}</div>@endif
        </div></div></div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header">Balances</div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Currency</th><th class="text-end">Balance</th><th class="text-end">Locked</th><th class="text-end">Available</th><th class="text-end">USD</th></tr></thead><tbody>
            @forelse($p['wallets'] as $w)
                <tr><td>{{ $w['currency'] }}</td><td class="text-end">{{ $w['balance'] }}</td><td class="text-end">{{ $w['locked'] }}</td><td class="text-end">{{ $w['available'] }}</td><td class="text-end">{{ $w['value_usd'] ?? '—' }}</td></tr>
            @empty<tr><td colspan="5" class="text-secondary">No balances.</td></tr>@endforelse
            </tbody></table>
        </div>
        <div class="card mb-3"><div class="card-header">Open positions</div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Symbol</th><th>Side</th><th class="text-end">Qty</th><th class="text-end">Entry</th><th class="text-end">Unrealized</th></tr></thead><tbody>
            @forelse($p['positions'] as $pos)
                <tr><td>{{ $pos['symbol'] }}</td><td class="{{ $pos['side'] === 'long' ? 'text-buy' : 'text-sell' }}">{{ $pos['side'] }} {{ $pos['leverage'] }}x</td><td class="text-end">{{ $pos['quantity'] }}</td><td class="text-end">{{ $pos['average_price'] }}</td><td class="text-end">{{ $pos['unrealized_pnl'] ?? '—' }} {{ $pos['currency'] }}</td></tr>
            @empty<tr><td colspan="5" class="text-secondary">No open positions.</td></tr>@endforelse
            </tbody></table>
        </div>
        <div class="card"><div class="card-header">Recent orders</div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Time</th><th>Symbol</th><th>Side</th><th class="text-end">Qty</th><th class="text-end">Avg price</th><th>Status</th></tr></thead><tbody>
            @forelse($overview['recent_orders'] as $o)
                <tr><td>{{ $o['created_at']->format('m-d H:i') }}</td><td>{{ $o['symbol'] }}</td><td class="text-{{ $o['side'] }}">{{ $o['side'] }} {{ $o['type'] }}</td><td class="text-end">{{ $o['quantity'] }}</td><td class="text-end">{{ $o['average_fill_price'] ?? '—' }}</td><td>{{ $o['status'] }}</td></tr>
            @empty<tr><td colspan="6" class="text-secondary">No orders yet. <a href="{{ route('trading.index') }}">Place one</a>.</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header">Market overview</div>
            <table class="table table-sm mb-0 num"><tbody>
            @foreach($overview['market'] as $m)
                <tr><td>{{ $m['symbol'] }} <span class="badge bg-secondary">{{ $m['market_type'] }}</span></td><td class="text-end">{{ $m['price'] ?? '—' }}</td>
                    <td class="text-end {{ ($m['change_24h'] ?? 0) >= 0 ? 'text-buy' : 'text-sell' }}">{{ $m['change_24h'] !== null ? number_format((float) $m['change_24h'], 2).'%' : '' }}</td></tr>
            @endforeach
            </tbody></table>
        </div>
        <div class="card mb-3"><div class="card-header">Telegram signals</div><div class="card-body small">
            @forelse($overview['signals'] as $s)
                <div>{{ $s->symbol }} <span class="text-{{ $s->signal_type }}">{{ strtoupper($s->signal_type) }}</span> — {{ $s->status }}</div>
            @empty<span class="text-secondary">Signal integration arrives in Phase 4.</span>@endforelse
        </div></div>
        <div class="card"><div class="card-header">Mining (30 days)</div><div class="card-body small">
            @forelse($overview['mining']['earnings_30d'] as $currency => $amount)
                <div>Earnings: {{ $amount }} {{ $currency }}</div>
            @empty<span class="text-secondary">No mining devices connected.</span>@endforelse
            <div>Expenses: ${{ $overview['mining']['expenses_30d'] }}</div>
        </div></div>
    </div>
</div>
@endsection
