@extends('layouts.app')
@section('title', 'Payments')
@section('content')
<div class="mb-3">@foreach(['pending','completed','failed','all'] as $s)<a href="?status={{ $s }}" class="btn btn-sm {{ request('status', 'pending') === $s ? 'btn-primary' : 'btn-outline-secondary' }} me-1">{{ ucfirst($s) }}</a>@endforeach</div>
<div class="card"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>User</th><th>Invoice</th><th class="text-end">Amount</th><th>Provider</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($payments as $p)
    <tr><td>{{ $p->created_at->format('Y-m-d H:i') }}</td><td>{{ $p->user->email }}</td><td>{{ $p->invoice?->number }}</td><td class="text-end">{{ \App\Support\Decimal::display($p->amount, 2) }} {{ $p->currency }}</td><td>{{ $p->provider }}</td><td>{{ $p->status }}</td>
        <td>@if($p->status === 'pending')@can('payments.manage')
            <form method="post" action="{{ route('admin.payments.confirm', $p) }}" class="d-inline-flex gap-1">@csrf<input name="reference" class="form-control form-control-sm" placeholder="Bank / tx reference" required><button class="btn btn-sm btn-success">Confirm</button></form>
            <form method="post" action="{{ route('admin.payments.fail', $p) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Reject</button></form>
        @endcan @endif</td></tr>
@empty<tr><td colspan="7" class="text-secondary">No payments.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-3">{{ $payments->links('pagination::bootstrap-5') }}</div>
@endsection
