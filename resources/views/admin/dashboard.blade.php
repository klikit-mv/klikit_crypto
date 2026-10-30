@extends('layouts.app')
@section('title', 'Platform overview')
@section('content')
@if($tradingHalted)<div class="alert alert-danger">Platform trading is <strong>halted</strong>. <a href="{{ route('admin.trading.controls') }}">Trading controls</a></div>@endif
<div class="row g-3 mb-4">
    @foreach([
        'users' => 'Users', 'active_subscriptions' => 'Active subscriptions', 'revenue_30d' => 'Revenue (30d)', 'orders_24h' => 'Orders (24h)',
        'failed_orders_24h' => 'Failed orders (24h)', 'running_bots' => 'Running bots', 'pending_payments' => 'Pending payments',
        'pending_deposits' => 'Pending deposits', 'pending_withdrawals' => 'Pending withdrawals', 'failed_jobs' => 'Failed jobs',
    ] as $key => $label)
        <div class="col-md-3 col-xl-2"><div class="card stat-card h-100"><div class="card-body">
            <div class="text-secondary small">{{ $label }}</div>
            <div class="value num">{{ $key === 'revenue_30d' ? '$'.number_format((float) $stats[$key], 2) : $stats[$key] }}</div>
        </div></div></div>
    @endforeach
</div>
<div class="card"><div class="card-header">Newest users</div>
    <table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Registered</th></tr></thead><tbody>
    @foreach($recentUsers as $u)
        <tr><td>@can('users.view')<a href="{{ route('admin.users.show', $u) }}">{{ $u->name }}</a>@else{{ $u->name }}@endcan</td><td>{{ $u->email }}</td><td>{{ $u->status }}</td><td>{{ $u->created_at->toDateString() }}</td></tr>
    @endforeach
    </tbody></table>
</div>
@endsection
