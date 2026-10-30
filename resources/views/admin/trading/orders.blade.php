@extends('layouts.app')
@section('title', 'Order monitor')
@section('content')
<div class="mb-3">@foreach(['', 'pending', 'open', 'filled', 'rejected', 'failed', 'cancelled'] as $s)<a href="?status={{ $s }}" class="btn btn-sm {{ request('status', '') === $s ? 'btn-primary' : 'btn-outline-secondary' }} me-1">{{ $s ?: 'All' }}</a>@endforeach</div>
<div class="card"><table class="table table-sm mb-0 num small"><thead><tr><th>Time</th><th>User</th><th>Symbol</th><th>Mode</th><th>Source</th><th>Side</th><th class="text-end">Qty</th><th class="text-end">Avg</th><th>Status</th><th>Reason</th></tr></thead><tbody>
@foreach($orders as $o)
    <tr><td>{{ $o->created_at->format('m-d H:i:s') }}</td><td>{{ $o->user->email }}</td><td>{{ $o->symbol->symbol }}</td><td>{{ $o->mode }}</td><td>{{ $o->source }}</td><td>{{ $o->side }} {{ $o->type }}</td>
        <td class="text-end">{{ \App\Support\Decimal::display($o->quantity) }}</td><td class="text-end">{{ $o->average_fill_price ? \App\Support\Decimal::display($o->average_fill_price) : '—' }}</td><td>{{ $o->status }}</td><td class="text-danger">{{ $o->reject_reason }}</td></tr>
@endforeach
</tbody></table></div>
<div class="mt-3">{{ $orders->links('pagination::bootstrap-5') }}</div>
@endsection
