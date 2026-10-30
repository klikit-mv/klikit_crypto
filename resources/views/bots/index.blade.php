@extends('layouts.app')
@section('title', 'Trading bots')
@section('content')
@if($maxBots === 0)
    <div class="alert alert-warning">Trading bots are included in the Pro and Enterprise plans. <a href="{{ route('subscription.index') }}">Upgrade</a></div>
@endif
<div class="alert alert-secondary small">Bots trade spot instruments in paper mode and every order passes your <a href="{{ route('trading.risk') }}">risk limits</a>.
    Semi-automatic bots propose trades for you to approve; fully automatic bots execute on their own. <a href="{{ route('bots.backtests') }}">Backtest a strategy &rarr;</a></div>

@if($pendingSignals->isNotEmpty())
<div class="card mb-3 border-warning"><div class="card-header">Bot proposals awaiting approval</div><table class="table table-sm mb-0"><tbody>
    @foreach($pendingSignals as $s)
        <tr><td>{{ $s->created_at->format('m-d H:i') }}</td><td>{{ $s->bot->name }}</td><td class="text-{{ $s->side }}">{{ strtoupper($s->side) }} {{ \App\Support\Decimal::display($s->quantity) }}</td><td class="small">{{ $s->reason }}</td>
            <td class="text-end text-nowrap"><form method="post" action="{{ route('bots.signals.approve', $s) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                <form method="post" action="{{ route('bots.signals.reject', $s) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Reject</button></form></td></tr>
    @endforeach
</tbody></table></div>
@endif

<div class="row g-3">
    <div class="col-lg-8"><div class="card"><div class="card-header">My bots ({{ $bots->count() }}{{ $maxBots > 0 ? ' / '.$maxBots : '' }})</div>
        <table class="table table-sm mb-0 num"><thead><tr><th>Name</th><th>Strategy</th><th>Symbol</th><th>Mode</th><th>Status</th><th class="text-end">Holding</th><th class="text-end">Realized P&amp;L</th><th></th></tr></thead><tbody>
        @forelse($bots as $bot)
            @php($s = $summaries[$bot->id])
            <tr><td><a href="{{ route('bots.show', $bot) }}">{{ $bot->name }}</a></td><td>{{ $bot->strategy->name }}</td><td>{{ $bot->symbol->symbol }}</td><td class="small">{{ str_replace('_', ' ', $bot->execution_mode) }}</td>
                <td><span class="badge bg-{{ ['running' => 'success', 'error' => 'danger'][$bot->status] ?? 'secondary' }}">{{ $bot->status }}</span></td>
                <td class="text-end">{{ \App\Support\Decimal::display($s['holding']) }}</td><td class="text-end {{ $s['realized']->isNegative() ? 'text-sell' : 'text-buy' }}">{{ \App\Support\Decimal::money($s['realized']) }}</td>
                <td class="text-end">@if($bot->status === 'running')<form method="post" action="{{ route('bots.stop', $bot) }}">@csrf<button class="btn btn-sm btn-outline-warning">Stop</button></form>
                    @else<form method="post" action="{{ route('bots.start', $bot) }}">@csrf<button class="btn btn-sm btn-outline-success">Start</button></form>@endif</td></tr>
        @empty<tr><td colspan="8" class="text-secondary">No bots yet.</td></tr>@endforelse
        </tbody></table></div></div>
    <div class="col-lg-4"><div class="card"><div class="card-header">New bot</div><div class="card-body">
        <form method="post" action="{{ route('bots.store') }}">@csrf
            <div class="mb-2"><input name="name" class="form-control" placeholder="Bot name" required></div>
            <div class="mb-2"><select name="strategy_id" id="bot-strategy" class="form-select">@foreach($strategies as $st)<option value="{{ $st->id }}">{{ $st->name }}</option>@endforeach</select></div>
            <div class="mb-2"><select name="symbol_id" class="form-select">@foreach($symbols as $sym)<option value="{{ $sym->id }}">{{ $sym->symbol }}</option>@endforeach</select></div>
            <div class="mb-2"><select name="execution_mode" class="form-select"><option value="semi_automatic">Semi-automatic (I approve each trade)</option><option value="fully_automatic" @disabled(! $automated)>Fully automatic</option></select></div>
            <div class="mb-3">@include('bots._params', ['selectId' => 'bot-strategy', 'values' => []])</div>
            <button class="btn btn-primary w-100" @disabled($maxBots === 0)>Create bot</button>
        </form>
    </div></div></div>
</div>
@endsection
