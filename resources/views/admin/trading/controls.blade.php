@extends('layouts.app')
@section('title', 'Trading controls')
@section('content')
<div class="row g-3">
    @can('trading.halt')
    <div class="col-lg-5"><div class="card border-{{ $halted ? 'danger' : 'success' }}"><div class="card-body">
        <h2 class="h5">Emergency stop</h2>
        <p>Platform trading is currently <strong>{{ $halted ? 'HALTED' : 'running' }}</strong>. Live routing: <strong>{{ $liveEnabled ? 'enabled' : 'disabled (paper only)' }}</strong>.</p>
        <form method="post" action="{{ route('admin.trading.halt') }}">@csrf<input type="hidden" name="halt" value="{{ $halted ? 0 : 1 }}">
            <button class="btn btn-{{ $halted ? 'success' : 'danger' }}">{{ $halted ? 'Resume trading' : 'Halt all trading' }}</button></form>
    </div></div></div>
    @endcan
    @can('trading.limits')
    <div class="col-lg-7"><div class="card"><div class="card-header">Platform-wide limits</div><div class="card-body">
        <p class="small text-secondary">Applied on top of each user's own risk profile.</p>
        <form method="post" action="{{ route('admin.trading.limits') }}">@csrf @method('put')
            @foreach(['max_order_notional' => 'Max order size (USD)', 'max_leverage' => 'Max leverage', 'max_open_positions' => 'Max open positions per user'] as $key => $label)
                <div class="mb-2"><label class="form-label">{{ $label }}</label><input name="{{ $key }}" value="{{ $limits[$key] }}" class="form-control"></div>
            @endforeach
            <button class="btn btn-primary">Save limits</button>
        </form>
    </div></div></div>
    @endcan
</div>
@endsection
