@extends('layouts.app')
@section('title', 'Bot monitor')
@section('content')
<div class="mb-3">@foreach(['' => 'All', 'running' => 'Running', 'error' => 'Errors', 'stopped' => 'Stopped'] as $s => $label)<a href="?status={{ $s }}" class="btn btn-sm {{ request('status', '') === $s ? 'btn-primary' : 'btn-outline-secondary' }} me-1">{{ $label }}</a>@endforeach</div>
<div class="card"><table class="table table-sm mb-0 small"><thead><tr><th>ID</th><th>User</th><th>Name</th><th>Strategy</th><th>Symbol</th><th>Mode</th><th>Status</th><th>Last run</th><th>Error</th><th></th></tr></thead><tbody>
@forelse($bots as $b)
    <tr><td>{{ $b->id }}</td><td>{{ $b->user->email }}</td><td>{{ $b->name }}</td><td>{{ $b->strategy->strategy_type }}</td><td>{{ $b->symbol->symbol }}</td><td>{{ $b->execution_mode }}</td>
        <td><span class="badge bg-{{ ['running' => 'success', 'error' => 'danger'][$b->status] ?? 'secondary' }}">{{ $b->status }}</span></td><td>{{ $b->last_run_at?->diffForHumans() }}</td><td class="text-danger">{{ $b->last_error }}</td>
        <td>@if($b->status === 'running')@can('trading.halt')<form method="post" action="{{ route('admin.bots.stop', $b) }}">@csrf<button class="btn btn-sm btn-outline-danger">Stop</button></form>@endcan @endif</td></tr>
@empty<tr><td colspan="10" class="text-secondary">No bots.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-3">{{ $bots->links('pagination::bootstrap-5') }}</div>
@endsection
