@extends('layouts.app')
@section('title', 'Audit logs')
@section('content')
<form class="row g-2 mb-3"><div class="col-md-3"><input name="action" value="{{ request('action') }}" class="form-control" placeholder="Action prefix, e.g. admin."></div>
    <div class="col-md-2"><input name="user_id" value="{{ request('user_id') }}" class="form-control" placeholder="User ID"></div><div class="col-auto"><button class="btn btn-outline-primary">Filter</button></div></form>
<div class="card"><table class="table table-sm mb-0 small"><thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Subject</th><th>Context</th><th>IP</th></tr></thead><tbody>
@foreach($logs as $log)
    <tr><td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td><td>{{ $log->user?->email ?? 'system' }}</td><td><code>{{ $log->action }}</code></td><td>{{ $log->subject_type ? class_basename($log->subject_type).'#'.$log->subject_id : '' }}</td>
        <td><code class="small">{{ $log->context ? json_encode($log->context) : '' }}</code></td><td>{{ $log->ip_address }}</td></tr>
@endforeach
</tbody></table></div>
<div class="mt-3">{{ $logs->links('pagination::bootstrap-5') }}</div>
@endsection
