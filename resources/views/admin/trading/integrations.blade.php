@extends('layouts.app')
@section('title', 'Exchanges & brokers')
@section('content')
<p class="text-secondary small">Live routing additionally requires <code>TRADING_LIVE_ENABLED=true</code> and a running execution bridge. Users connect their own API keys; prefer keys without withdrawal permission.</p>
@foreach(['exchange' => $exchanges, 'broker' => $brokers] as $type => $venues)
<div class="card mb-3"><div class="card-header">{{ ucfirst($type) }}s</div><table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Code</th><th>Integration</th><th>Connected accounts</th><th>Status</th></tr></thead><tbody>
@foreach($venues as $v)
    <tr><td>{{ $v->name }}</td><td>{{ $v->code }}</td><td>{{ $v->api_type ?? $v->integration_type }}</td><td>{{ $v->accounts_count }}</td>
        <td><form method="post" action="{{ route('admin.integrations.update', [$type, $v->id]) }}" class="d-flex gap-1">@csrf @method('put')
            <select name="status" class="form-select form-select-sm" style="width:auto">@foreach(['enabled','disabled','maintenance'] as $s)<option @selected($v->status === $s)>{{ $s }}</option>@endforeach</select>
            <button class="btn btn-sm btn-outline-primary">Save</button></form></td></tr>
@endforeach
</tbody></table></div>
@endforeach
@endsection
