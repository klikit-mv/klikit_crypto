@extends('layouts.app')
@section('title', 'Mining pools & devices')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-lg-5"><div class="card"><div class="card-header">Add pool</div><div class="card-body">
        <form method="post" action="{{ route('admin.mining.pools.store') }}" class="row g-2">@csrf
            <div class="col-6"><input name="name" class="form-control form-control-sm" placeholder="Name" required></div>
            <div class="col-6"><input name="endpoint" class="form-control form-control-sm" placeholder="stratum+tcp://…" required></div>
            <div class="col-4"><input name="algorithm" class="form-control form-control-sm" placeholder="SHA-256" required></div>
            <div class="col-4"><input name="coin" class="form-control form-control-sm" placeholder="BTC" required></div>
            <div class="col-4"><input name="fee_percent" class="form-control form-control-sm" placeholder="Fee %" required></div>
            <div class="col-4"><select name="api_type" class="form-select form-select-sm"><option value="generic_json">JSON API</option><option value="manual">Manual</option></select></div>
            <div class="col-8"><input name="api_url" class="form-control form-control-sm" placeholder="https://pool-adapter.example.com"></div>
            <div class="col-12"><button class="btn btn-sm btn-primary w-100">Add pool</button></div>
        </form></div></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header">Pools</div><table class="table table-sm mb-0 small"><tbody>
        @foreach($pools as $p)
            <tr><td>{{ $p->name }}</td><td>{{ $p->coin }} · {{ $p->algorithm }}</td><td>{{ $p->fee_percent }}%</td><td>{{ $p->api_type }}</td><td>{{ $p->accounts_count }} accounts</td>
                <td><form method="post" action="{{ route('admin.mining.pools.toggle', $p) }}">@csrf<button class="btn btn-sm btn-outline-{{ $p->is_active ? 'success' : 'secondary' }}">{{ $p->is_active ? 'active' : 'disabled' }}</button></form></td></tr>
        @endforeach
    </tbody></table></div></div>
</div>
<div class="card"><div class="card-header">Devices (latest 100)</div><table class="table table-sm mb-0 small"><thead><tr><th>User</th><th>Device</th><th>Pool</th><th>Status</th><th>Last seen</th></tr></thead><tbody>
    @foreach($devices as $d)<tr><td>{{ $d->user->email }}</td><td>{{ $d->name }} ({{ $d->device_type }})</td><td>{{ $d->pool?->name }}</td><td>{{ $d->status }}</td><td>{{ $d->last_seen_at?->diffForHumans() ?? 'never' }}</td></tr>@endforeach
</tbody></table></div>
@endsection
