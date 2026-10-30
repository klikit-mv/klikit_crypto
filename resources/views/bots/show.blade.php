@extends('layouts.app')
@section('title', $bot->name)
@section('content')
<div class="row g-3 mb-3">
    @foreach([
        ['Status', $bot->status], ['Strategy', $bot->strategy->name], ['Symbol', $bot->symbol->symbol],
        ['Holding', \App\Support\Decimal::display($summary['holding']).' '.$bot->symbol->base_currency],
        ['Realized P&L', \App\Support\Decimal::money($summary['realized']).' '.$bot->symbol->quote_currency],
        ['Win rate', $summary['trades'] ? round($summary['wins'] / $summary['trades'] * 100, 1).'% of '.$summary['trades'] : '—'],
    ] as [$label, $value])
        <div class="col-md-2"><div class="card stat-card h-100"><div class="card-body"><div class="small text-secondary">{{ $label }}</div><div class="fw-semibold num">{{ $value }}</div></div></div></div>
    @endforeach
</div>
@if($bot->last_error)<div class="alert alert-danger">Last error: {{ $bot->last_error }}</div>@endif
<div class="d-flex gap-2 mb-3">
    @if($bot->status === 'running')<form method="post" action="{{ route('bots.stop', $bot) }}">@csrf<button class="btn btn-warning">Stop bot</button></form>
    @else<form method="post" action="{{ route('bots.start', $bot) }}">@csrf<button class="btn btn-success">Start bot</button></form>@endif
    <form method="post" action="{{ route('bots.destroy', $bot) }}" onsubmit="return confirm('Delete this bot?')">@csrf @method('delete')<button class="btn btn-outline-danger">Delete</button></form>
    <span class="align-self-center small text-secondary">Last run: {{ $bot->last_run_at?->diffForHumans() ?? 'never' }} · {{ str_replace('_', ' ', $bot->execution_mode) }}</span>
</div>
<div class="row g-3">
    <div class="col-lg-5"><div class="card"><div class="card-header">Parameters</div><div class="card-body">
        <form method="post" action="{{ route('bots.update', $bot) }}" class="row g-2">@csrf @method('put')
            @foreach($parameters as $key => $value)
                <div class="col-6"><label class="form-label small mb-0">{{ str_replace('_', ' ', $key) }}</label><input name="parameters[{{ $key }}]" value="{{ $value }}" class="form-control form-control-sm"></div>
            @endforeach
            <div class="col-12"><button class="btn btn-sm btn-primary">Save parameters</button></div>
        </form>
    </div></div>
    <div class="card mt-3"><div class="card-header">Runs</div><ul class="list-group list-group-flush small">
        @foreach($runs as $r)<li class="list-group-item">{{ $r->started_at->format('Y-m-d H:i') }} → {{ $r->stopped_at?->format('Y-m-d H:i') ?? 'now' }} · {{ $r->status }} {{ $r->error }}</li>@endforeach
    </ul></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header">Decisions</div><table class="table table-sm mb-0 small num"><tbody>
        @forelse($signals as $s)
            <tr><td>{{ $s->created_at->format('m-d H:i') }}</td><td class="text-{{ $s->side }}">{{ strtoupper($s->side) }}</td><td>{{ \App\Support\Decimal::display($s->quantity) }}</td><td>{{ $s->reason }}</td><td>{{ $s->status }}</td><td>{{ $s->order?->average_fill_price ? '@ '.\App\Support\Decimal::display($s->order->average_fill_price) : '' }}</td></tr>
        @empty<tr><td class="text-secondary">No decisions yet.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
