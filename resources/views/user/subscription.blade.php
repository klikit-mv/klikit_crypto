@extends('layouts.app')
@section('title', 'Subscription')
@section('content')
@if($current)
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <span>Current plan: <strong>{{ $current->plan->name }}</strong> — expires {{ $current->expires_at->toDateString() }} {{ $current->auto_renew ? '(auto-renews)' : '(will not renew)' }}</span>
        @if($current->auto_renew)<form method="post" action="{{ route('subscription.cancel') }}">@csrf<button class="btn btn-sm btn-outline-light">Cancel renewal</button></form>@endif
    </div>
@else
    <div class="alert alert-secondary">You are on the free tier (paper trading only).</div>
@endif
<div class="row g-3 mb-4">
    @foreach($plans as $plan)
        <div class="col-md-4"><div class="card h-100 @if($plan->is_featured) border-primary @endif"><div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between"><h2 class="h5">{{ $plan->name }}</h2>@if($plan->is_featured)<span class="badge bg-primary align-self-start">Popular</span>@endif</div>
            <div class="fs-3 fw-bold">${{ \App\Support\Decimal::display($plan->price, 2) }} <span class="fs-6 text-secondary">/ {{ $plan->billing_period }}</span></div>
            <p class="text-secondary small">{{ $plan->description }}</p>
            <ul class="small flex-grow-1">
                @foreach($plan->features as $f)
                    @continue(in_array($f->feature_value, ['false', '0']))
                    <li>{{ $featureLabels[$f->feature_key] ?? $f->feature_key }}@if(! in_array($f->feature_value, ['true'])): {{ $f->feature_value === '-1' ? 'unlimited' : $f->feature_value }}@endif</li>
                @endforeach
            </ul>
            <form method="post" action="{{ route('subscription.checkout') }}">@csrf
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <div class="input-group input-group-sm mb-2"><input name="coupon" class="form-control" placeholder="Coupon code"></div>
                <button class="btn btn-primary w-100">{{ $current?->plan_id === $plan->id ? 'Renew' : 'Choose '.$plan->name }}</button>
            </form>
        </div></div></div>
    @endforeach
</div>
<div class="card"><div class="card-header">Billing history</div>
    <table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Description</th><th class="text-end">Total</th><th>Status</th><th>Date</th></tr></thead><tbody>
    @forelse($invoices as $inv)
        <tr><td><a href="{{ route('subscription.invoice', $inv) }}">{{ $inv->number }}</a></td><td>{{ $inv->description }}</td><td class="text-end">{{ \App\Support\Decimal::display($inv->total, 2) }} {{ $inv->currency }}</td><td>{{ $inv->status }}</td><td>{{ $inv->created_at->toDateString() }}</td></tr>
    @empty<tr><td colspan="5" class="text-secondary">No invoices.</td></tr>@endforelse
    </tbody></table>
</div>
@endsection
