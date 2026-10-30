@extends('layouts.app')
@section('title', 'Subscription plans')
@section('content')
<a href="{{ route('admin.plans.create') }}" class="btn btn-primary mb-3">New plan</a>
<div class="card"><table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Slug</th><th class="text-end">Price</th><th>Period</th><th>Features</th><th>Subscribers</th><th>Active</th><th></th></tr></thead><tbody>
@foreach($plans as $plan)
    <tr><td>{{ $plan->name }}</td><td>{{ $plan->slug }}</td><td class="text-end">{{ \App\Support\Decimal::display($plan->price, 2) }} {{ $plan->currency }}</td><td>{{ $plan->billing_period }}</td>
        <td class="small">{{ $plan->features->map(fn ($f) => $f->feature_key.'='.$f->feature_value)->join(', ') }}</td><td>{{ $plan->subscriptions_count }}</td><td>{{ $plan->is_active ? 'yes' : 'no' }}</td>
        <td><a href="{{ route('admin.plans.edit', $plan) }}" class="btn btn-sm btn-outline-secondary">Edit</a></td></tr>
@endforeach
</tbody></table></div>
@endsection
