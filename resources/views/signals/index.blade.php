@extends('layouts.app')
@section('title', 'Telegram signals')
@section('content')
@unless($enabled)
    <div class="alert alert-warning">Telegram signals are included in the Pro and Enterprise plans. <a href="{{ route('subscription.index') }}">Upgrade</a></div>
@endunless
<div class="alert alert-secondary small">Every signal passes the parser (ambiguous messages are discarded) and then the same risk engine as your manual orders. Signals execute in paper mode.</div>

<div class="card mb-3"><div class="card-header">Awaiting your approval</div>
    <table class="table table-sm mb-0 num"><tbody>
    @forelse($pending as $e)
        @php($s = $e->signal)
        <tr><td>{{ $s->received_at->format('m-d H:i') }}</td><td>{{ $s->channel->channel_name }}</td><td><strong>{{ $s->symbol }}</strong> <span class="badge bg-secondary">{{ $s->market_type }}</span></td>
            <td class="text-{{ $s->signal_type }}">{{ strtoupper($s->signal_type) }} @if($s->leverage > 1){{ \App\Support\Decimal::display($s->leverage) }}x @endif</td>
            <td>Entry {{ $s->entry_price ? \App\Support\Decimal::display($s->entry_price).($s->entry_price_max ? ' – '.\App\Support\Decimal::display($s->entry_price_max) : '') : 'market' }}</td>
            <td>SL {{ \App\Support\Decimal::display($s->stop_loss) }}</td><td>TP {{ $s->targets->map(fn ($t) => \App\Support\Decimal::display($t->target_price))->join(' / ') }}</td>
            <td class="small text-secondary">expires {{ $s->expires_at?->diffForHumans() }}</td>
            <td class="text-end text-nowrap">
                <form method="post" action="{{ route('signals.approve', $e) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                <form method="post" action="{{ route('signals.reject', $e) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Reject</button></form></td></tr>
    @empty<tr><td class="text-secondary">Nothing waiting.</td></tr>@endforelse
    </tbody></table>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header">Signal channels</div><ul class="list-group list-group-flush">
        @forelse($channels as $c)
            @php($sub = $mySubscriptions->get($c->id))
            <li class="list-group-item">
                <div class="d-flex justify-content-between"><strong>{{ $c->channel_name }}</strong>
                    @if($sub)<form method="post" action="{{ route('signals.unsubscribe', $c) }}">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0">Unsubscribe</button></form>@endif</div>
                @if($enabled)
                <form method="post" action="{{ route('signals.subscribe', $c) }}" class="row g-2 mt-1">@csrf
                    <div class="col-4"><select name="execution_mode" class="form-select form-select-sm"><option value="manual_approval">Approve each</option><option value="auto" @selected($sub?->execution_mode === 'auto')>Auto-execute</option></select></div>
                    <div class="col-3"><input name="amount_per_signal" value="{{ $sub ? \App\Support\Decimal::display($sub->amount_per_signal, 2) : 100 }}" class="form-control form-control-sm" title="USD per signal"></div>
                    <div class="col-2"><input name="max_leverage" value="{{ $sub ? \App\Support\Decimal::display($sub->max_leverage, 2) : 1 }}" class="form-control form-control-sm" title="Max leverage"></div>
                    <div class="col-3"><button class="btn btn-sm btn-primary w-100">{{ $sub ? 'Update' : 'Subscribe' }}</button></div>
                </form>
                @endif
            </li>
        @empty<li class="list-group-item text-secondary">No channels have been configured yet.</li>@endforelse
    </ul></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header">History</div><table class="table table-sm mb-0 small"><tbody>
        @forelse($history as $e)
            <tr><td>{{ $e->created_at->format('m-d H:i') }}</td><td>{{ $e->signal->symbol }} {{ strtoupper($e->signal->signal_type) }}</td><td>{{ $e->status }}</td><td class="text-secondary">{{ $e->reason }}</td><td>{{ $e->order?->status }}</td></tr>
        @empty<tr><td class="text-secondary">No signals yet.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
