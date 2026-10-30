@extends('layouts.app')
@section('title', $user->name)
@section('content')
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <div>{{ $user->email }}</div>
            <div class="small text-secondary">Joined {{ $user->created_at->toDateString() }} · Country {{ $user->profile?->country ?? '—' }} · KYC {{ $user->profile?->kyc_status ?? '—' }}</div>
            <div class="mt-2">Plan: <strong>{{ $subscription?->plan->name ?? 'Free' }}</strong> @if($subscription)until {{ $subscription->expires_at->toDateString() }}@endif</div>
            @can('users.manage')
            <form method="post" action="{{ route('admin.users.status', $user) }}" class="d-flex gap-2 mt-3">@csrf @method('put')
                <select name="status" class="form-select form-select-sm">@foreach(['active','suspended','closed'] as $s)<option @selected($user->status === $s)>{{ $s }}</option>@endforeach</select>
                <button class="btn btn-sm btn-outline-warning">Update status</button>
            </form>
            @endcan
        </div></div>
        @can('users.roles')
        <div class="card mb-3"><div class="card-header">Roles</div><div class="card-body">
            <form method="post" action="{{ route('admin.users.roles', $user) }}">@csrf @method('put')
                @foreach($roles as $role)
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role->id }}" id="r{{ $role->id }}" @checked($user->roles->contains($role))><label class="form-check-label" for="r{{ $role->id }}">{{ $role->label }}</label></div>
                @endforeach
                <button class="btn btn-sm btn-outline-primary mt-2">Save roles</button>
            </form>
        </div></div>
        @endcan
        <div class="card"><div class="card-header">Connected venues</div><ul class="list-group list-group-flush small">
            @forelse($exchangeAccounts as $a)<li class="list-group-item">{{ $a->exchange->name }} — {{ $a->label }} ({{ $a->permissions_scope }}, {{ $a->status }})</li>@empty @endforelse
            @forelse($brokerAccounts as $a)<li class="list-group-item">{{ $a->broker->name }} — {{ $a->label }} ({{ $a->permissions_scope }}, {{ $a->status }})</li>@empty @endforelse
            <li class="list-group-item text-secondary">API secrets are encrypted and never shown to staff.</li>
        </ul></div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-header">Paper portfolio — ${{ $portfolio['total_usd'] }}</div>
            <table class="table table-sm mb-0 num"><tbody>@foreach($portfolio['wallets'] as $w)<tr><td>{{ $w['currency'] }}</td><td class="text-end">{{ $w['balance'] }}</td><td class="text-end">locked {{ $w['locked'] }}</td></tr>@endforeach</tbody></table>
        </div>
        <div class="card"><div class="card-header">Recent orders</div>
            <table class="table table-sm mb-0 num"><tbody>@foreach($orders as $o)<tr><td>{{ $o->created_at->format('m-d H:i') }}</td><td>{{ $o->symbol->symbol }}</td><td>{{ $o->side }} {{ $o->type }}</td><td class="text-end">{{ \App\Support\Decimal::display($o->quantity) }}</td><td>{{ $o->mode }}</td><td>{{ $o->status }}</td></tr>@endforeach</tbody></table>
        </div>
    </div>
</div>
@endsection
