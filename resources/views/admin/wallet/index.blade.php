@extends('layouts.app')
@section('title', 'Deposits & withdrawals')
@section('content')
<div class="card mb-3"><div class="card-header">Pending deposits</div><table class="table table-sm mb-0"><tbody>
@forelse($deposits as $d)
    <tr><td>{{ $d->created_at->format('Y-m-d H:i') }}</td><td>{{ $d->user->email }}</td><td class="text-end">{{ \App\Support\Decimal::display($d->amount) }} {{ $d->currency }}</td><td>{{ $d->method }} {{ $d->reference }}</td>
        <td class="text-end">@can('deposits.review')@foreach(['approve' => 'success', 'reject' => 'outline-danger'] as $decision => $style)<form method="post" action="{{ route('admin.deposits.review', $d) }}" class="d-inline">@csrf<input type="hidden" name="decision" value="{{ $decision }}"><button class="btn btn-sm btn-{{ $style }}">{{ ucfirst($decision) }}</button></form> @endforeach @endcan</td></tr>
@empty<tr><td class="text-secondary">None.</td></tr>@endforelse
</tbody></table></div>
<div class="card mb-3"><div class="card-header">Pending withdrawals</div><table class="table table-sm mb-0"><tbody>
@forelse($withdrawals as $w)
    <tr><td>{{ $w->created_at->format('Y-m-d H:i') }}</td><td>{{ $w->user->email }}</td><td class="text-end">{{ \App\Support\Decimal::display($w->amount) }} {{ $w->currency }}</td><td class="small">{{ $w->destination }}</td>
        <td class="text-end">@can('withdrawals.review')<form method="post" action="{{ route('admin.withdrawals.review', $w) }}" class="d-inline-flex gap-1">@csrf<input name="note" class="form-control form-control-sm" placeholder="Note">
            <button name="decision" value="approve" class="btn btn-sm btn-success">Approve</button><button name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button></form>@endcan</td></tr>
@empty<tr><td class="text-secondary">None.</td></tr>@endforelse
</tbody></table></div>
<div class="card"><div class="card-header">Recently reviewed withdrawals</div><table class="table table-sm mb-0 small"><tbody>
@foreach($recent as $w)<tr><td>{{ $w->reviewed_at?->format('Y-m-d H:i') }}</td><td>{{ $w->user->email }}</td><td>{{ \App\Support\Decimal::display($w->amount) }} {{ $w->currency }}</td><td>{{ $w->status }}</td><td>by {{ $w->reviewer?->email }}</td></tr>@endforeach
</tbody></table></div>
@endsection
