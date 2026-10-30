@extends('layouts.app')
@section('title', 'Users')
@section('content')
<form class="row g-2 mb-3"><div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search name or email"></div>
    <div class="col-md-2"><select name="status" class="form-select"><option value="">Any status</option>@foreach(['active','suspended','closed'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-outline-primary">Filter</button></div></form>
<div class="card"><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th>Last login</th></tr></thead><tbody>
@foreach($users as $u)
    <tr><td>{{ $u->id }}</td><td><a href="{{ route('admin.users.show', $u) }}">{{ $u->name }}</a></td><td>{{ $u->email }}</td><td>{{ $u->roles->pluck('label')->join(', ') }}</td>
        <td><span class="badge bg-{{ $u->status === 'active' ? 'success' : 'danger' }}">{{ $u->status }}</span></td><td>{{ $u->last_login_at?->diffForHumans() ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
<div class="mt-3">{{ $users->links('pagination::bootstrap-5') }}</div>
@endsection
